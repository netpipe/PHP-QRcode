<?php
declare(strict_types=1);

/**
 * qrcode.php — Self-contained QR Code generator (ISO/IEC 18004, versions 1–40)
 * Requires PHP 7.4+ (works on 8.x). No extensions needed (GD used if available).
 *
 * LIBRARY:
 *   require 'qrcode.php';
 *   file_put_contents('qr.png', QRCode::png('https://example.com', QRCode::ECC_H));
 *   echo QRCode::svg('Hello world');
 *   $qr = QRCode::encode('HELLO WORLD', 'Q');   // 'L','M','Q','H' or ECC_* constants
 *   $matrix = $qr->getMatrix();                 // rows[y][x] === true means dark
 *
 * HTTP (run directly):  qrcode.php?text=Hello&ecc=M&format=png&size=8&download=1
 * CLI  (run directly):  php qrcode.php "https://example.com" H
 */

final class QRCode
{
    public const ECC_L = 0; // ~7% recovery
    public const ECC_M = 1; // ~15%
    public const ECC_Q = 2; // ~25%
    public const ECC_H = 3; // ~30%

    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    private const FORMAT_BITS = [1, 0, 3, 2]; // L, M, Q, H

    // ECC codewords per block, indexed [ecc][version] (index 0 unused)
    private const ECC_LEN = [
        [-1,7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [-1,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28],
        [-1,13,22,18,26,18,24,18,22,20,24,28,26,24,20,30,24,28,28,26,30,28,30,30,30,30,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [-1,17,28,22,16,22,28,26,26,24,28,24,28,22,24,24,30,28,28,26,28,30,24,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
    ];
    // Number of ECC blocks, indexed [ecc][version]
    private const BLOCKS = [
        [-1,1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
        [-1,1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
        [-1,1,1,2,2,4,4,6,6,8,8,8,10,12,16,12,17,16,18,21,20,23,23,25,27,29,34,34,35,38,40,43,45,48,51,53,56,59,62,65,68],
        [-1,1,1,2,4,4,4,5,6,8,8,11,11,16,16,18,16,19,21,25,25,25,34,30,32,35,37,40,42,45,48,51,54,57,60,63,66,70,74,77,81],
    ];

    private int $version;
    private int $ecc;
    private int $mask;
    private int $size;
    private array $modules;    // [y][x] => bool (true = dark)
    private array $isFunction; // [y][x] => bool
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    // =====================================================================
    // PUBLIC API
    // =====================================================================

    /** Encode text. $ecc: ECC_* constant or 'L'/'M'/'Q'/'H'. $mask: 0–7 or null (auto). */
    public static function encode(string $text, $ecc = self::ECC_M, int $minVersion = 1, int $maxVersion = 40, ?int $mask = null): self
    {
        $ecc = self::normalizeEcc($ecc);
        if ($text === '') throw new InvalidArgumentException('Text must not be empty.');
        $minVersion = max(1, min(40, $minVersion));
        $maxVersion = max($minVersion, min(40, $maxVersion));
        if ($mask !== null && ($mask < 0 || $mask > 7)) throw new InvalidArgumentException('Mask must be 0..7.');

        // Choose the most compact mode: numeric > alphanumeric > byte
        $len = strlen($text);
        if (ctype_digit($text)) {
            $mode = 0;
            $dataBits = intdiv($len, 3) * 10 + [0, 4, 7][$len % 3];
        } elseif (strspn($text, self::ALNUM) === $len) {
            $mode = 1;
            $dataBits = intdiv($len, 2) * 11 + ($len % 2) * 6;
        } else {
            $mode = 2; // byte mode (UTF-8 / binary safe)
            $dataBits = $len * 8;
        }

        // Smallest version that fits
        $version = 0; $cc = 0;
        for ($v = $minVersion; $v <= $maxVersion; $v++) {
            $cc = self::charCountBits($mode, $v);
            if ($len < (1 << $cc) && 4 + $cc + $dataBits <= self::numDataCodewords($v, $ecc) * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) throw new RuntimeException('Data too long for the selected version range / ECC level.');

        // Build bit stream: mode indicator + character count + data
        $bits = self::toBits([1, 2, 4][$mode], 4) . self::toBits($len, $cc);
        if ($mode === 0) {
            for ($i = 0; $i < $len; $i += 3) {
                $chunk = substr($text, $i, 3);
                $bits .= self::toBits((int)$chunk, strlen($chunk) * 3 + 1);
            }
        } elseif ($mode === 1) {
            for ($i = 0; $i + 1 < $len; $i += 2) {
                $bits .= self::toBits(strpos(self::ALNUM, $text[$i]) * 45 + strpos(self::ALNUM, $text[$i + 1]), 11);
            }
            if ($len % 2 === 1) $bits .= self::toBits(strpos(self::ALNUM, $text[$len - 1]), 6);
        } else {
            for ($i = 0; $i < $len; $i++) $bits .= self::toBits(ord($text[$i]), 8);
        }

        // Terminator, byte alignment, pad codewords
        $cap = self::numDataCodewords($version, $ecc) * 8;
        $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        for ($pad = 0xEC; strlen($bits) < $cap; $pad ^= 0xEC ^ 0x11) {
            $bits .= self::toBits($pad, 8);
        }

        $data = array_map('bindec', str_split($bits, 8));
        return new self($version, $ecc, self::addEccAndInterleave($data, $version, $ecc), $mask);
    }

    /** Convenience: encode + render PNG (binary string). */
    public static function png(string $text, $ecc = self::ECC_M, int $scale = 8, int $border = 4): string
    {
        return self::encode($text, $ecc)->toPNG($scale, $border);
    }

    /** Convenience: encode + render SVG (XML string). */
    public static function svg(string $text, $ecc = self::ECC_M, int $scale = 8, int $border = 4): string
    {
        return self::encode($text, $ecc)->toSVG($scale, $border);
    }

    public function getSize(): int    { return $this->size; }    // modules per side
    public function getVersion(): int { return $this->version; }
    public function getMask(): int    { return $this->mask; }
    public function getMatrix(): array { return $this->modules; } // rows[y][x], true = dark

    /** SVG image. $scale = px per module, $border = quiet zone in modules (spec: 4). */
    public function toSVG(int $scale = 8, int $border = 4, string $dark = '#000000', string $light = '#ffffff'): string
    {
        $scale = max(1, $scale); $border = max(0, $border);
        $dim = ($this->size + 2 * $border) * $scale;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            $x = 0;
            while ($x < $this->size) {
                if (!$this->modules[$y][$x]) { $x++; continue; }
                $run = 1;
                while ($x + $run < $this->size && $this->modules[$y][$x + $run]) $run++;
                $px = ($x + $border) * $scale;
                $py = ($y + $border) * $scale;
                $w = $run * $scale;
                $path .= "M{$px} {$py}h{$w}v{$scale}h-{$w}z";
                $x += $run;
            }
        }
        $dark = htmlspecialchars($dark, ENT_QUOTES, 'UTF-8');
        $bg = '';
        if (strtolower($light) !== 'transparent') {
            $light = htmlspecialchars($light, ENT_QUOTES, 'UTF-8');
            $bg = "<rect width=\"100%\" height=\"100%\" fill=\"{$light}\"/>";
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$dim}\" height=\"{$dim}\" "
            . "viewBox=\"0 0 {$dim} {$dim}\" shape-rendering=\"crispEdges\">{$bg}"
            . "<path d=\"{$path}\" fill=\"{$dark}\"/></svg>\n";
    }

    /** PNG image (binary string). Uses GD if present, otherwise a pure-PHP PNG writer. */
    public function toPNG(int $scale = 8, int $border = 4, array $dark = [0, 0, 0], array $light = [255, 255, 255]): string
    {
        $scale = max(1, $scale); $border = max(0, $border);
        $dark = self::normalizeColor($dark);
        $light = self::normalizeColor($light);
        $dim = ($this->size + 2 * $border) * $scale;

        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($dim, $dim);
            $cLight = imagecolorallocate($im, $light[0], $light[1], $light[2]);
            $cDark = imagecolorallocate($im, $dark[0], $dark[1], $dark[2]);
            imagefilledrectangle($im, 0, 0, $dim - 1, $dim - 1, $cLight);
            for ($y = 0; $y < $this->size; $y++) {
                for ($x = 0; $x < $this->size; $x++) {
                    if ($this->modules[$y][$x]) {
                        $px = ($x + $border) * $scale;
                        $py = ($y + $border) * $scale;
                        imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $cDark);
                    }
                }
            }
            ob_start();
            imagepng($im);
            imagedestroy($im);
            return (string)ob_get_clean();
        }
        return $this->renderPngPure($dim, $scale, $border, $dark, $light);
    }

    /** Data URI for embedding in HTML/CSS: <img src="<?= $qr->toDataURL() ?>"> */
    public function toDataURL(int $scale = 8, int $border = 4): string
    {
        return 'data:image/png;base64,' . base64_encode($this->toPNG($scale, $border));
    }

    /** UTF-8 terminal rendering (half blocks). $invert for light-on-dark terminals. */
    public function toText(int $border = 2, bool $invert = false): string
    {
        $n = $this->size;
        $w = $n + 2 * $border;
        $get = function (int $x, int $y) use ($n, $border, $invert): bool {
            $x -= $border; $y -= $border;
            $dark = ($x >= 0 && $x < $n && $y >= 0 && $y < $n) && $this->modules[$y][$x];
            return $invert ? !$dark : $dark;
        };
        $out = '';
        for ($y = 0; $y < $w; $y += 2) {
            for ($x = 0; $x < $w; $x++) {
                $top = $get($x, $y);
                $bot = $get($x, $y + 1);
                $out .= $top && $bot ? '█' : ($top ? '▀' : ($bot ? '▄' : ' '));
            }
            $out .= "\n";
        }
        return $out;
    }

    // =====================================================================
    // MATRIX CONSTRUCTION
    // =====================================================================

    private function __construct(int $version, int $ecc, array $codewords, ?int $mask)
    {
        $this->version = $version;
        $this->ecc = $ecc;
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);

        if ($mask === null) { // Auto: pick mask with lowest penalty score
            $best = 0;
            $minPenalty = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $this->applyMask($m);
                $this->drawFormatBits($m);
                $p = $this->penaltyScore();
                if ($p < $minPenalty) { $minPenalty = $p; $best = $m; }
                $this->applyMask($m); // XOR is its own inverse -> undo
            }
            $mask = $best;
        }
        $this->applyMask($mask);
        $this->drawFormatBits($mask);
        $this->mask = $mask;
    }

    private function setFunctionModule(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) { // timing patterns
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($n - 4, 3);
        $this->drawFinderPattern(3, $n - 4);

        $pos = $this->alignmentPositions();
        $cnt = count($pos);
        for ($i = 0; $i < $cnt; $i++) {
            for ($j = 0; $j < $cnt; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $cnt - 1) || ($i === $cnt - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunctionModule($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // placeholder; overwritten with real mask later
        $this->drawVersion();
    }

    private function drawFinderPattern(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunctionModule($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function alignmentPositions(): array
    {
        if ($this->version === 1) return [];
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int)(ceil(($this->version * 4 + 4) / ($numAlign * 2 - 2)) * 2);
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS[$this->ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) !== 0;
        $n = $this->size;

        for ($i = 0; $i <= 5; $i++) $this->setFunctionModule(8, $i, $bit($i));
        $this->setFunctionModule(8, 7, $bit(6));
        $this->setFunctionModule(8, 8, $bit(7));
        $this->setFunctionModule(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->setFunctionModule(14 - $i, 8, $bit($i));

        for ($i = 0; $i < 8; $i++) $this->setFunctionModule($n - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->setFunctionModule(8, $n - 15 + $i, $bit($i));
        $this->setFunctionModule(8, $n - 8, true); // always-dark module
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) return;
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $bit);
            $this->setFunctionModule($b, $a, $bit);
        }
    }

    private function drawCodewords(array $data): void
    {
        $n = $this->size;
        $i = 0;
        $len = count($data) * 8;
        for ($right = $n - 1; $right >= 1; $right -= 2) { // zigzag, column pairs
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $y = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $len) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) continue;
                switch ($mask) {
                    case 0: $invert = ($x + $y) % 2 === 0; break;
                    case 1: $invert = $y % 2 === 0; break;
                    case 2: $invert = $x % 3 === 0; break;
                    case 3: $invert = ($x + $y) % 3 === 0; break;
                    case 4: $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $invert = (($x * $y) % 2 + ($x * $y) % 3) === 0; break;
                    case 6: $invert = (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    case 7: $invert = (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0; break;
                    default: throw new InvalidArgumentException('Bad mask');
                }
                if ($invert) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    private function penaltyScore(): int
    {
        $n = $this->size;
        $p = 0;
        for ($y = 0; $y < $n; $y++) { // rows: N1 runs + N3 finder-like
            $row = '';
            for ($x = 0; $x < $n; $x++) $row .= $this->modules[$y][$x] ? '1' : '0';
            $p += $this->linePenalty($row);
        }
        for ($x = 0; $x < $n; $x++) { // columns
            $col = '';
            for ($y = 0; $y < $n; $y++) $col .= $this->modules[$y][$x] ? '1' : '0';
            $p += $this->linePenalty($col);
        }
        for ($y = 0; $y < $n - 1; $y++) { // N2: 2x2 blocks
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) $p += 3;
            }
        }
        $dark = 0; // N4: dark/light balance
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) if ($this->modules[$y][$x]) $dark++;
        $total = $n * $n; // odd, so the ratio is never exactly 50%
        $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $p + $k * 10;
    }

    private function linePenalty(string $line): int
    {
        $p = 0;
        if (preg_match_all('/1{5,}|0{5,}/', $line, $m)) {
            foreach ($m[0] as $run) $p += 3 + (strlen($run) - 5);
        }
        $p += 40 * (int)preg_match_all('/(?=10111010000|00001011101)/', $line, $m2);
        return $p;
    }

    // =====================================================================
    // REED–SOLOMON / CAPACITY MATH
    // =====================================================================

    private static function addEccAndInterleave(array $data, int $ver, int $ecc): array
    {
        $numBlocks = self::BLOCKS[$ecc][$ver];
        $blockEccLen = self::ECC_LEN[$ecc][$ver];
        $rawCodewords = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($blockEccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortLen - $blockEccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;
            $ec = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) $dat[] = 0; // interleave placeholder, skipped below
            $blocks[] = array_merge($dat, $ec);
        }
        $result = [];
        for ($i = 0, $cnt = count($blocks[0]); $i < $cnt; $i++) {
            for ($j = 0; $j < $numBlocks; $j++) {
                if ($i !== $shortLen - $blockEccLen || $j >= $numShort) {
                    $result[] = $blocks[$j][$i];
                }
            }
        }
        return $result;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = self::gfMul($root, 2);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) $result[$i] ^= self::gfMul($coef, $factor);
        }
        return $result;
    }

    /** Multiply in GF(256) with primitive polynomial 0x11D (log/exp tables). */
    private static function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) return 0;
        if (self::$gfExp === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $v = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $v;
                $log[$v] = $i;
                $v <<= 1;
                if ($v & 0x100) $v ^= 0x11D;
            }
            for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
            self::$gfExp = $exp;
            self::$gfLog = $log;
        }
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) $result -= 36;
        }
        return $result;
    }

    private static function numDataCodewords(int $ver, int $ecc): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_LEN[$ecc][$ver] * self::BLOCKS[$ecc][$ver];
    }

    private static function charCountBits(int $mode, int $ver): int
    {
        $idx = $ver <= 9 ? 0 : ($ver <= 26 ? 1 : 2);
        return [[10, 12, 14], [9, 11, 13], [8, 16, 16]][$mode][$idx];
    }

    // =====================================================================
    // HELPERS / PURE-PHP PNG WRITER
    // =====================================================================

    private static function normalizeEcc($ecc): int
    {
        if (is_string($ecc)) {
            $map = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
            $k = strtoupper(trim($ecc));
            if (isset($map[$k])) return $map[$k];
        }
        if (is_int($ecc) && $ecc >= 0 && $ecc <= 3) return $ecc;
        throw new InvalidArgumentException('ECC must be ECC_L/M/Q/H (0–3) or "L","M","Q","H".');
    }

    private static function normalizeColor(array $c): array
    {
        if (count($c) !== 3) throw new InvalidArgumentException('Color must be [r, g, b].');
        return array_map(static fn($v): int => max(0, min(255, (int)$v)), array_values($c));
    }

    private static function toBits(int $val, int $n): string
    {
        $s = decbin($val);
        if (strlen($s) > $n) throw new RuntimeException('Bit stream overflow.');
        return str_pad($s, $n, '0', STR_PAD_LEFT);
    }

    private function renderPngPure(int $dim, int $scale, int $border, array $dark, array $light): string
    {
        $d = chr($dark[0]) . chr($dark[1]) . chr($dark[2]);
        $l = chr($light[0]) . chr($light[1]) . chr($light[2]);
        $lightLine = str_repeat($l, $dim);
        $pad = str_repeat($l, $border * $scale);
        $raw = str_repeat("\x00" . $lightLine, $border * $scale);
        foreach ($this->modules as $row) {
            $line = $pad;
            foreach ($row as $m) $line .= str_repeat($m ? $d : $l, $scale);
            $line .= $pad;
            $raw .= str_repeat("\x00" . $line, $scale);
        }
        $raw .= str_repeat("\x00" . $lightLine, $border * $scale);

        $z = function_exists('gzcompress') ? gzcompress($raw, 6) : self::zlibStored($raw);
        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $dim, $dim, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', $z)
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** zlib stream using uncompressed deflate blocks — no extensions required. */
    private static function zlibStored(string $data): string
    {
        $out = "\x78\x01";
        $len = strlen($data);
        for ($pos = 0; ; $pos += 65535) {
            $chunk = substr($data, $pos, 65535);
            $cl = strlen($chunk);
            $last = ($pos + $cl >= $len) ? 1 : 0;
            $out .= chr($last) . pack('vv', $cl, $cl ^ 0xFFFF) . $chunk;
            if ($last) break;
        }
        return $out . hash('adler32', $data, true);
    }
}

// =========================================================================
// Standalone endpoint / CLI tool — runs ONLY when this file is executed
// directly. When require'd/included as a library, nothing below runs.
// =========================================================================
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {

    if (PHP_SAPI === 'cli') {
        $text = $argv[1] ?? '';
        if ($text === '') {
            fwrite(STDERR, "Usage: php qrcode.php \"text to encode\" [L|M|Q|H]\n");
            exit(1);
        }
        try {
            echo QRCode::encode($text, $argv[2] ?? 'M')->toText();
        } catch (Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            exit(1);
        }
        exit(0);
    }

    $text = (string)($_GET['text'] ?? $_POST['text'] ?? '');
    if ($text === '') { // tiny demo form
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>QR Code Generator</title>'
            . '<form style="font-family:sans-serif;max-width:420px;margin:3rem auto">'
            . '<h2>QR Code Generator</h2>'
            . '<p><input name="text" placeholder="Text or URL" required style="width:100%;padding:.5rem"></p>'
            . '<p>ECC: <select name="ecc"><option>L</option><option selected>M</option><option>Q</option><option>H</option></select> '
            . 'Format: <select name="format"><option>png</option><option>svg</option></select> '
            . 'Scale: <input name="size" type="number" value="8" min="1" max="40" style="width:4rem"></p>'
            . '<p><button>Generate</button></p></form>';
        exit;
    }
    if (strlen($text) > 4096) { http_response_code(400); exit('Text too long.'); }

    $format = strtolower((string)($_GET['format'] ?? 'png')) === 'svg' ? 'svg' : 'png';
    $scale = max(1, min(40, (int)($_GET['size'] ?? 8)));
    $border = max(0, min(16, (int)($_GET['border'] ?? 4)));

    try {
        $qr = QRCode::encode($text, (string)($_GET['ecc'] ?? 'M'));
        $body = $format === 'svg' ? $qr->toSVG($scale, $border) : $qr->toPNG($scale, $border);
    } catch (Throwable $e) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('QR error: ' . $e->getMessage());
    }
    header('Content-Type: ' . ($format === 'svg' ? 'image/svg+xml' : 'image/png'));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: public, max-age=86400');
    if (isset($_GET['download'])) {
        header('Content-Disposition: attachment; filename="qrcode.' . $format . '"');
    }
    echo $body;
    exit;
}