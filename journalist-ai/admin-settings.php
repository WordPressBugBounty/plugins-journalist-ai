<?php
    if (!defined('ABSPATH')) exit; // Exit if accessed directly
?>
<div class="wrap">
    <h1>Arvow Settings</h1>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="journalistai-settings-form">
        <?php
            $webhook_url = esc_url(rest_url('journalistai/v1/webhook'));
            $secret_value = get_option('journalistai_secret');
        ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label>Webhook URL</label></th>
                    <td>
                        <input type="text" class="regular-text code" readonly value="<?php echo esc_attr($webhook_url); ?>" />
                        <p class="description">Copy this URL to configure the integration in the Arvow dashboard.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="journalistai-secret">Secret</label></th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:8px; align-items:center;">
                            <?php wp_nonce_field('journalistai_update_secret_action', 'journalistai_update_secret_nonce'); ?>
                            <input type="hidden" name="action" value="journalistai_update_secret" />
                            <input name="secret" id="journalistai-secret" type="text" class="regular-text" value="<?php echo esc_attr($secret_value); ?>" />
                            <button type="submit" class="button">Update Secret</button>
                        </form>
                        <p class="description">This secret authorizes requests from Arvow. It must match the secret configured in your Arvow integration.</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank">
        <?php wp_nonce_field('journalistai_connect_action', 'journalistai_connect_nonce'); ?>
        <input type="hidden" name="action" value="journalistai_connect">
        <p>You can connect this site to Arvow using the current secret.</p>
        <p>
            <button type="submit" id="journalistai-connection-button" class="button button-primary">Connect</button>
        </p>
    </form>
</div>