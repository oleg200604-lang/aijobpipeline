<?php

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">

<h1>Settings</h1>

<form method="post" action="options.php">

<?php

settings_fields('aijp_settings_group');

do_settings_sections('aijp-settings');

submit_button();

?>

</form>

</div>