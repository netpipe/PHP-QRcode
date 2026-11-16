# PHP-QRcode
QR code for php single file include
<br>
works standalone with gui or
<br>
// 1) As an included library<br>
require 'qrcode.php';<br>
file_put_contents('qr.png', QRCode::png('https://example.com', QRCode::ECC_H));<br>
echo QRCode::svg('Hello world');                      // inline SVG<br>
echo < img src="' . QRCode::encode('Hi')->toDataURL() . '">';<br>
$qr = QRCode::encode('HELLO WORLD', 'Q', 2);          // force min version 2<br>
<br><br>
2) As an image endpoint<br>
< img src="qrcode.php?text=https://example.com&ecc=M&format=png&size=8"><br><br>
# 3) From the terminal (prints a scannable QR)<br>
php qrcode.php "https://example.com" H
