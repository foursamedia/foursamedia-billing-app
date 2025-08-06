<?php
// -- Pesan WhatsApp untuk New Customer --
$whatsapp_message_new_customer = "Halo {nama_pelanggan} yang terhormat,

Selamat datang di keluarga Foursamedia! Akun Anda telah berhasil dibuat.

Detail Akun:
ID Pelanggan: {username}
Email: {email}
Paket: {paket}



Terima kasih telah memilih kami.
Salam hangat,
Tim Foursamedia";

// -- Pesan WhatsApp untuk Billing --
$whatsapp_message_billing = "Halo Bapak/Ibu {nama_pelanggan},

Info Tagihan Foursamedia Anda untuk bulan {bulan_tagihan} telah terbit.

Jumlah Total Tagihan (termasuk tunggakan): Rp {jumlah_tagihan}
Jatuh Tempo: {tanggal_jatuh_tempo}

{rekap_tunggakan}

Mohon segera lakukan pembayaran untuk menghindari gangguan layanan.

Terima kasih atas perhatiannya.
Salam hormat,
Tim Foursamedia";

// -- Pesan WhatsApp untuk Invoice --
$whatsapp_message_invoice = "Halo Bapak/Ibu {nama_pelanggan},

Berikut adalah Invoice Foursamedia Anda:
Nomor Invoice: {nomor_invoice}
Tanggal Invoice: {tanggal_invoice}
Jumlah: Rp {jumlah_invoice}
Jatuh Tempo: {tanggal_jatuh_tempo_invoice}

Anda dapat melihat dan mengunduh invoice di: {link_view_invoice}

Terima kasih.
Salam hormat,
Tim Foursamedia";

// -- Pesan WhatsApp untuk Payment Confirmation --
$whatsapp_message_payment_confirmation = "Terima kasih, Bapak/Ibu {nama_pelanggan}!

Pembayaran Anda sejumlah Rp {jumlah_pembayaran} untuk Invoice No. {nomor_invoice} pada tanggal {tanggal_pembayaran} telah berhasil kami terima.

Metode Pembayaran: {metode_pembayaran}
Deskripsi Pembayaran: {description} 

Layanan internet Anda kini aktif kembali/tetap aktif. Terima kasih atas kepercayaan Anda.

Salam,
Tim Foursamedia";

// -- Pesan WhatsApp untuk Down Service Info --
$whatsapp_message_down_service_info = "Halo {nama_pelanggan},


Anda dapat login ke portal pelanggan kami untuk mengelola layanan Anda di: {link_portal_pelanggan}/// untuk pelanggan baru

Kami ingin memberitahukan bahwa saat ini sedang terjadi gangguan layanan di area Anda ({detail_gangguan}). Tim teknisi kami sedang berupaya maksimal untuk perbaikan.

Estimasi waktu perbaikan: {estimasi_perbaikan}.
Mohon maaf atas ketidaknyamanannya.

Terima kasih atas pengertiannya.
Tim Foursamedia";

?>