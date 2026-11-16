# PHP-QRcode
QR code for php single file include

works standalone with gui or

// 1) As an included library
require 'qrcode.php';
file_put_contents('qr.png', QRCode::png('https://example.com', QRCode::ECC_H));
echo QRCode::svg('Hello world');                      // inline SVG
echo '<img src="' . QRCode::encode('Hi')->toDataURL() . '">';
$qr = QRCode::encode('HELLO WORLD', 'Q', 2);          // force min version 2

<!-- 2) As an image endpoint -->
<img src="qrcode.php?text=https://example.com&ecc=M&format=png&size=8">

# 3) From the terminal (prints a scannable QR)
php qrcode.php "https://example.com" H