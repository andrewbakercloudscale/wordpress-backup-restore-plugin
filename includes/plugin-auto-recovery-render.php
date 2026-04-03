<?php
/**
 * Plugin Auto Recovery — admin tab HTML.
 *
 * Rendered inside the main plugin page by calling
 * csbr_par_render_tab() from the page template.
 *
 * @package CloudScale_Backup
 * @since   3.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Output the Plugin Auto Recovery tab panel.
 *
 * @since 3.3.0
 */
function csbr_par_render_tab(): void {
	$s = CSBR_Plugin_Auto_Recovery::get_settings();
	?>
	<div id="cs-tab-autorecovery" class="cs-tab-panel" style="display:none">
	<hr style="border:none;border-top:3px solid #37474f;margin:18px 0 16px;">
	<div class="cs-grid cs-grid-1" style="display:flex!important;flex-direction:column!important;gap:16px!important;">

	<!-- ════════════════════ SETTINGS CARD ════════════════════ -->
	<div class="cs-card cs-card--blue">
	  <div class="cs-card-stripe cs-stripe--blue" style="background:linear-gradient(135deg,#1565c0 0%,#1976d2 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
	    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128737; Plugin Auto Recovery Settings</h2>
	    <button type="button" onclick="csParExplain()" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;letter-spacing:0.01em;">&#128214; <?php esc_html_e( 'Explain…', 'cloudscale-backup-restore' ); ?></button>
	  </div>

	  <!-- Enable -->
	  <div class="cs-field-group">
	    <label class="cs-enable-label">
	      <input type="checkbox" id="par-enabled" <?php checked( $s['enabled'] ); ?>>
	      <?php esc_html_e( 'Enable Plugin Auto Recovery', 'cloudscale-backup-restore' ); ?>
	    </label>
	    <p class="cs-help"><?php esc_html_e( 'Backs up each plugin before it is updated, then monitors the site and automatically restores the previous version if a failure is detected.', 'cloudscale-backup-restore' ); ?></p>
	  </div>

	  <div id="par-main-controls">

	    <!-- Monitoring window -->
	    <div class="cs-field-group">
	      <label class="cs-field-label" for="par-window"><?php esc_html_e( 'Monitoring window', 'cloudscale-backup-restore' ); ?></label>
	      <div class="cs-inline">
	        <input type="number" id="par-window" value="<?php echo (int) $s['window_minutes']; ?>" min="1" max="30" class="cs-input-sm">
	        <span class="cs-muted-text"><?php esc_html_e( 'minutes after each update (1–30)', 'cloudscale-backup-restore' ); ?></span>
	      </div>
	      <p class="cs-help"><?php esc_html_e( 'The watchdog probes the site every minute during this window. Two consecutive failures trigger an automatic rollback.', 'cloudscale-backup-restore' ); ?></p>
	    </div>

	    <!-- Health check URL -->
	    <div class="cs-field-group">
	      <label class="cs-field-label" for="par-health-url"><?php esc_html_e( 'Health check URL', 'cloudscale-backup-restore' ); ?></label>
	      <div class="cs-inline">
	        <input type="url" id="par-health-url" value="<?php echo esc_attr( $s['health_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" style="width:390px;padding:4px 8px;height:32px;">
	      </div>
	      <p class="cs-help"><?php esc_html_e( 'Leave blank to use the site home URL. A 5xx response or connection failure is treated as unhealthy. 4xx responses (including 404) are treated as healthy — the server is up.', 'cloudscale-backup-restore' ); ?></p>
	      <div style="margin-top:8px;">
	        <button type="button" id="par-test-health-btn" class="button"><?php esc_html_e( 'Test Health Check', 'cloudscale-backup-restore' ); ?></button>
	        <span id="par-test-health-msg" style="font-size:0.88rem;margin-left:8px;"></span>
	      </div>
	    </div>

	    <!-- SMS -->
	    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;">
	      <label class="cs-enable-label">
	        <input type="checkbox" id="par-sms-enabled" <?php checked( $s['sms_enabled'] ); ?>>
	        <?php esc_html_e( 'SMS alerts via Twilio', 'cloudscale-backup-restore' ); ?>
	      </label>
	      <p class="cs-help"><?php esc_html_e( 'Sends an SMS when a plugin is rolled back. Requires a Twilio account (twilio.com — free trial available). Email alerts are always sent on rollback and use the settings in the Backup Schedule card.', 'cloudscale-backup-restore' ); ?></p>

	      <div id="par-sms-controls" style="margin-top:10px;<?php echo $s['sms_enabled'] ? '' : 'display:none;'; ?>">
	        <?php
	        $twilio_fields = [
	          'par-twilio-sid'   => [ 'par_twilio_sid',   'Account SID',  $s['twilio_sid'],   'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'text' ],
	          'par-twilio-token' => [ 'par_twilio_token', 'Auth Token',   $s['twilio_token'], 'Your Auth Token', 'password' ],
	          'par-twilio-from'  => [ 'par_twilio_from',  'From number',  $s['twilio_from'],  '+12025551234', 'tel' ],
	          'par-twilio-to'    => [ 'par_twilio_to',    'To number',    $s['twilio_to'],    '+12025556789', 'tel' ],
	        ];
	        foreach ( $twilio_fields as $field_id => [ $name, $label, $value, $placeholder, $type ] ) : ?>
	        <div style="margin-bottom:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
	          <label class="cs-field-label" for="<?php echo esc_attr( $field_id ); ?>" style="min-width:110px;"><?php echo esc_html( $label ); ?></label>
	          <input type="<?php echo esc_attr( $type ); ?>"
	                 id="<?php echo esc_attr( $field_id ); ?>"
	                 name="<?php echo esc_attr( $name ); ?>"
	                 value="<?php echo esc_attr( $value ); ?>"
	                 placeholder="<?php echo esc_attr( $placeholder ); ?>"
	                 autocomplete="off"
	                 style="width:280px;padding:4px 8px;height:32px;">
	        </div>
	        <?php endforeach; ?>
	        <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
	          <button type="button" id="par-test-sms-btn" class="button"><?php esc_html_e( 'Send Test SMS', 'cloudscale-backup-restore' ); ?></button>
	          <span id="par-test-sms-msg" style="font-size:0.88rem;"></span>
	        </div>
	      </div>
	    </div>

	  </div><!-- /#par-main-controls -->

	  <div style="margin-top:20px;">
	    <button type="button" id="par-save-btn" class="button button-primary"><?php esc_html_e( 'Save Plugin Auto Recovery Settings', 'cloudscale-backup-restore' ); ?></button>
	    <span id="par-save-msg" style="font-size:0.88rem;margin-left:8px;"></span>
	  </div>
	</div><!-- /.cs-card--blue -->

	<!-- ════════════════════ WATCHDOG SETUP CARD ════════════════════ -->
	<div class="cs-card" style="border:1px solid #e2e8f0;background:#fff;">
	  <div style="background:linear-gradient(135deg,#263238 0%,#37474f 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
	    <h2 style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128290; Watchdog Script — System Cron Setup</h2>
	    <span id="par-watchdog-status" style="font-size:0.78rem;font-weight:600;color:#80cbc4;"></span>
	  </div>

	  <p style="font-size:0.9rem;color:#334155;margin-bottom:12px;"><?php esc_html_e( 'Plugin Auto Recovery uses a system cron watchdog (not WP-Cron) so it can detect and recover from PHP fatal errors that crash WordPress entirely. Copy this script to your server and add the cron line below.', 'cloudscale-backup-restore' ); ?></p>

	  <p style="font-size:0.85rem;color:#64748b;margin-bottom:8px;"><strong><?php esc_html_e( 'Step 1 — Copy this script to the server:', 'cloudscale-backup-restore' ); ?></strong></p>
	  <div style="position:relative;margin-bottom:16px;">
	    <pre id="par-watchdog-script" style="background:#1e1e1e;color:#d4d4d4;padding:12px 16px;border-radius:6px;font-size:0.78rem;overflow-x:auto;white-space:pre;max-height:220px;overflow-y:auto;margin:0;"><?php
	      echo esc_html( CSBR_Plugin_Auto_Recovery::generate_watchdog_script() );
	    ?></pre>
	    <button type="button" id="par-copy-script-btn" class="button" style="position:absolute;top:8px;right:8px;font-size:0.75rem;"><?php esc_html_e( 'Copy', 'cloudscale-backup-restore' ); ?></button>
	  </div>

	  <p style="font-size:0.85rem;color:#64748b;margin-bottom:8px;"><strong><?php esc_html_e( 'Step 2 — Save it on the server and make it executable:', 'cloudscale-backup-restore' ); ?></strong></p>
	  <pre style="background:#1e1e1e;color:#d4d4d4;padding:10px 16px;border-radius:6px;font-size:0.82rem;margin-bottom:16px;">sudo tee /usr/local/bin/csbr-par-watchdog.sh &lt;&lt;'EOF'
(paste script)
EOF
sudo chmod +x /usr/local/bin/csbr-par-watchdog.sh</pre>

	  <p style="font-size:0.85rem;color:#64748b;margin-bottom:8px;"><strong><?php esc_html_e( 'Step 3 — Add to root\'s crontab (runs every minute):', 'cloudscale-backup-restore' ); ?></strong></p>
	  <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
	    <code id="par-cron-line" style="background:#f1f5f9;padding:6px 12px;border-radius:4px;font-size:0.85rem;border:1px solid #e2e8f0;">* * * * * root /usr/local/bin/csbr-par-watchdog.sh &gt;&gt; /var/log/cloudscale-par.log 2&gt;&amp;1</code>
	    <button type="button" id="par-copy-cron-btn" class="button" style="font-size:0.78rem;"><?php esc_html_e( 'Copy', 'cloudscale-backup-restore' ); ?></button>
	  </div>
	  <p style="font-size:0.82rem;color:#64748b;">sudo crontab -e</p>
	</div>

	<!-- ════════════════════ ACTIVE MONITORS CARD ════════════════════ -->
	<div class="cs-card cs-card--teal" id="par-monitors-card">
	  <div class="cs-card-stripe cs-stripe--teal" style="background:linear-gradient(135deg,#00695c 0%,#00897b 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
	    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128202; Active Monitors</h2>
	    <button type="button" id="par-refresh-btn" style="background:#1a1a1a;border:none;color:#f9a825;border-radius:999px;padding:5px 16px;font-size:0.78rem;font-weight:700;cursor:pointer;">&#8635; Refresh</button>
	  </div>
	  <div id="par-monitors-body">
	    <p style="color:#78909c;font-size:0.88rem;"><?php esc_html_e( 'Loading…', 'cloudscale-backup-restore' ); ?></p>
	  </div>
	</div>

	<!-- ════════════════════ ROLLBACK HISTORY CARD ════════════════════ -->
	<div class="cs-card cs-card--green" id="par-history-card">
	  <div class="cs-card-stripe cs-stripe--green" style="background:linear-gradient(135deg,#2e7d32 0%,#43a047 100%);display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:52px;margin:0 -20px 20px -20px;border-radius:10px 10px 0 0;">
	    <h2 class="cs-card-heading" style="color:#fff!important;font-size:0.95rem;font-weight:700;margin:0;padding:0;line-height:1.3;border:none;background:none;text-shadow:0 1px 3px rgba(0,0,0,0.3);">&#128203; Rollback History</h2>
	  </div>
	  <div id="par-history-body">
	    <p style="color:#78909c;font-size:0.88rem;"><?php esc_html_e( 'Loading…', 'cloudscale-backup-restore' ); ?></p>
	  </div>
	</div>

	<!-- ════════════════════ HOW IT WORKS ════════════════════ -->
	<div class="cs-card" style="border:1px solid #e2e8f0;background:#f8fafc;">
	  <h3 style="font-size:0.9rem;font-weight:700;color:#0f172a;margin:0 0 12px;"><?php esc_html_e( 'How Plugin Auto Recovery works', 'cloudscale-backup-restore' ); ?></h3>
	  <ol style="margin:0 0 0 1.2em;padding:0;font-size:0.88rem;color:#334155;line-height:1.9;">
	    <li><?php esc_html_e( 'Before any plugin update, the current plugin directory is copied to a secure backup location on the server.', 'cloudscale-backup-restore' ); ?></li>
	    <li><?php esc_html_e( 'After the update, the system-cron watchdog probes the health check URL every minute for the monitoring window.', 'cloudscale-backup-restore' ); ?></li>
	    <li><?php esc_html_e( 'If two consecutive probes fail (5xx error or connection timeout), the watchdog renames the broken plugin directory and copies the backup back — no WordPress or WP-CLI required for the core rollback.', 'cloudscale-backup-restore' ); ?></li>
	    <li><?php esc_html_e( 'On the next WordPress page load, the rollback is recorded in history and an email (and optional SMS) is sent.', 'cloudscale-backup-restore' ); ?></li>
	    <li><?php esc_html_e( 'While a crash is in progress, visitors see a branded "Plugin Auto Recovery is recovering this site" page instead of a white screen of death.', 'cloudscale-backup-restore' ); ?></li>
	  </ol>
	  <p style="margin:14px 0 0;font-size:0.82rem;color:#64748b;padding:10px;background:#fff;border-radius:6px;border:1px solid #e2e8f0;">
	    <strong><?php esc_html_e( 'Why system cron and not WP-Cron?', 'cloudscale-backup-restore' ); ?></strong>
	    <?php esc_html_e( ' If a plugin update causes a PHP fatal error, WordPress crashes and wp-cron.php never fires. A system-cron job running every minute via a bash script operates completely outside of WordPress and can detect and fix the problem even when the entire site is returning 500 errors.', 'cloudscale-backup-restore' ); ?>
	  </p>
	</div>

	</div><!-- /.cs-grid -->
	</div><!-- /#cs-tab-autorecovery -->
	<?php
}
