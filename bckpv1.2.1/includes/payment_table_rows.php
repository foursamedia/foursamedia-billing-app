<?php
// This file is included by manage_payments.php to render table rows
// It expects $payments array to be available.

if (!empty($payments)) {
    foreach ($payments as $payment) {
        ?>
        <tr>
            <td><?= htmlspecialchars($payment['payment_id']) ?></td>
            <td><?= htmlspecialchars($payment['customer_name']) ?></td>
            <td>Rp <?= number_format($payment['amount'], 2, ',', '.') ?></td>
            <td><?= htmlspecialchars(date('d-m-Y', strtotime($payment['payment_date']))) ?></td>
            <td><?= htmlspecialchars($payment['description']) ?></td>
            <td><?= htmlspecialchars($payment['inputter_name'] ?: 'N/A') ?> (<?= htmlspecialchars($payment['inputter_email'] ?: 'N/A') ?>)</td>
            <td>
                <a href="edit_payment.php?id=<?= $payment['payment_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                <a href="delete_payment.php?id=<?= $payment['payment_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus pembayaran ini?');">Hapus</a>
            </td>
        </tr>
        <?php
    }
} else {
    ?>
    <tr>
        <td colspan="7" class="text-center">Tidak ada data pembayaran yang ditemukan.</td>
    </tr>
    <?php
}
?>