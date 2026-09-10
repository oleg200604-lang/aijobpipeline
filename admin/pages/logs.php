<?php

if (!defined('ABSPATH')) {
    exit;
}

$logs = AIJP_Log_Repository::get_all();
?>

<div class="wrap">

<h1>Logs</h1>

<table class="widefat striped">

<thead>
<tr>
    <th>ID</th>
    <th>Level</th>
    <th>Message</th>
    <th>Date</th>
</tr>
</thead>

<tbody>

<?php foreach ($logs as $log): ?>

<tr>
    <td><?php echo esc_html($log->id); ?></td>
    <td><?php echo esc_html($log->level); ?></td>
    <td><?php echo esc_html($log->message); ?></td>
    <td><?php echo esc_html($log->created_at); ?></td>
</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>