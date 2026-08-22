<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Admin {

	public function register_menu(): void {
		add_menu_page( 'WP MCP', 'WP MCP', 'manage_options', 'wpmcp', array( $this, 'render_connection' ), 'dashicons-rest-api', 81 );
		add_submenu_page( 'wpmcp', 'Connection', 'Connection', 'manage_options', 'wpmcp', array( $this, 'render_connection' ) );
		add_submenu_page( 'wpmcp', 'Tools', 'Tools', 'manage_options', 'wpmcp-tools', array( $this, 'render_tools' ) );
		add_submenu_page( 'wpmcp', 'History', 'History', 'manage_options', 'wpmcp-history', array( $this, 'render_history' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wpmcp' ) ) {
			return;
		}
		wp_enqueue_style( 'wpmcp-admin', WPMCP_URL . 'admin/css/admin.css', array(), WPMCP_VERSION );
	}

	public function render_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['wpmcp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wpmcp_nonce'] ), 'wpmcp_settings' ) ) {
			update_option( 'wpmcp_server_enabled', isset( $_POST['wpmcp_server_enabled'] ) ? 1 : 0 );
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}
		if ( isset( $_GET['revoke_client'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmcp_revoke_' . sanitize_key( wp_unslash( $_GET['revoke_client'] ) ) ) ) {
			WPMCP_OAuth::revoke_client( sanitize_key( wp_unslash( $_GET['revoke_client'] ) ) );
			echo '<div class="notice notice-success"><p>Connected app revoked.</p></div>';
		}
		$enabled  = (bool) get_option( 'wpmcp_server_enabled', 1 );
		$endpoint = WPMCP_Auth::endpoint_url();
		$snippets = WPMCP_Auth::client_config_snippets();
		$status   = wpmcp_plugin()->seo->status();
		$clients  = WPMCP_OAuth::clients();
		$oauth_on = WPMCP_OAuth::enabled();
		?>
		<div class="wrap wpmcp-wrap">
			<h1>WP MCP Suite</h1>
			<p>Connect Claude, Cursor, Codex or any MCP client to this WordPress site.</p>

			<div class="card wpmcp-card">
				<h2>Server</h2>
				<form method="post">
					<?php wp_nonce_field( 'wpmcp_settings', 'wpmcp_nonce' ); ?>
					<label>
						<input type="checkbox" name="wpmcp_server_enabled" value="1" <?php checked( $enabled ); ?>>
						Enable MCP server
					</label>
					<p><button class="button button-primary" type="submit">Save</button></p>
				</form>
				<p><strong>Endpoint:</strong><br><code><?php echo esc_html( $endpoint ); ?></code></p>
				<p><strong>Auth:</strong> Application Password required.
					<a href="<?php echo esc_url( WPMCP_Auth::profile_url() ); ?>">Create one under Users &rarr; Profile</a>,
					then send HTTP Basic auth (<code>user:app-password</code>, base64).</p>
			</div>

			<div class="card wpmcp-card">
				<h2>Claude Code</h2>
				<pre><code><?php echo esc_html( $snippets['claude_code'] ); ?></code></pre>
				<h2>Claude Desktop</h2>
				<pre><code><?php echo esc_html( $snippets['claude_desktop'] ); ?></code></pre>
				<h2>Cursor (mcp.json)</h2>
				<pre><code><?php echo esc_html( $snippets['cursor'] ); ?></code></pre>
				<h2>Codex (config.toml)</h2>
				<pre><code><?php echo esc_html( $snippets['codex'] ); ?></code></pre>
			</div>

			<div class="card wpmcp-card">
				<h2>SEO integration</h2>
				<p>Active adapter: <strong><?php echo esc_html( $status['label'] ); ?></strong></p>
				<ul>
					<?php foreach ( $status['detected'] as $detected ) : ?>
						<li>
							<?php echo esc_html( $detected['label'] ); ?>:
							<?php echo $detected['active'] ? 'active' : 'not active'; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="card wpmcp-card">
				<h2>OAuth sign-in</h2>
				<?php if ( ! $oauth_on ) : ?>
					<p>OAuth is unavailable: it requires HTTPS (or the <code>WPMCP_ALLOW_INSECURE_OAUTH</code> constant for local dev). Application Passwords work everywhere.</p>
				<?php else : ?>
					<p>MCP clients can connect through OAuth 2.1 (PKCE) without copying a password. Discovery:
						<code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></code></p>
				<?php endif; ?>
				<h3>Connected apps</h3>
				<?php if ( empty( $clients ) ) : ?>
					<p>No apps connected yet.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr><th>App</th><th>Client ID</th><th>Connected</th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $clients as $client_id => $client ) : ?>
								<tr>
									<td><?php echo esc_html( $client['name'] ); ?></td>
									<td><code><?php echo esc_html( $client_id ); ?></code></td>
									<td><?php echo esc_html( $client['created_at'] ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wpmcp&revoke_client=' . rawurlencode( $client_id ) ), 'wpmcp_revoke_' . $client_id ) ); ?>">Revoke</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<div class="card wpmcp-card">
				<h2>License</h2>
				<?php if ( WPMCP_License::is_pro() ) : ?>
					<p><strong>Pro is active.</strong><?php echo defined( 'WPMCP_PRO' ) && WPMCP_PRO ? ' (via WPMCP_PRO constant)' : ''; ?></p>
					<form method="post">
						<?php wp_nonce_field( 'wpmcp_license', 'wpmcp_license_nonce' ); ?>
						<button class="button" name="wpmcp_deactivate_license" value="1">Deactivate</button>
					</form>
				<?php else : ?>
					<p>Free build. Pro tools register automatically once a license is active (define <code>WPMCP_PRO</code> for development).</p>
					<form method="post">
						<?php wp_nonce_field( 'wpmcp_license', 'wpmcp_license_nonce' ); ?>
						<input type="text" name="wpmcp_license_key" class="regular-text" placeholder="License key">
						<button class="button button-primary">Activate</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function render_tools(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['wpmcp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wpmcp_nonce'] ), 'wpmcp_tools' ) ) {
			$disabled = array();
			foreach ( wpmcp_plugin()->registry->all() as $name => $tool ) {
				if ( empty( $_POST[ 'tool_' . $name ] ) ) {
					$disabled[] = $name;
				}
			}
			update_option( 'wpmcp_disabled_tools', $disabled );
			update_option( 'wpmcp_compact_mode', isset( $_POST['wpmcp_compact_mode'] ) ? 1 : 0 );
			echo '<div class="notice notice-success"><p>Tools saved.</p></div>';
		}
		$disabled = (array) get_option( 'wpmcp_disabled_tools', array() );
		$by_category = array();
		foreach ( wpmcp_plugin()->registry->all() as $name => $tool ) {
			$by_category[ $tool['category'] ][ $name ] = $tool;
		}
		?>
		<div class="wrap wpmcp-wrap">
			<h1>Tools</h1>
			<p>Write tools are off by default. Reads stay on.</p>
			<form method="post">
				<?php wp_nonce_field( 'wpmcp_tools', 'wpmcp_nonce' ); ?>
				<p>
					<label>
						<input type="checkbox" name="wpmcp_compact_mode" value="1" <?php checked( (bool) get_option( 'wpmcp_compact_mode', 0 ) ); ?>>
						<strong>Compact tool mode</strong> — collapse the whole surface into 3 meta-tools (<code>list-tools</code>, <code>get-tool-schema</code>, <code>call-tool</code>) for clients with tool-count caps. Per-tool toggles still gate what <code>call-tool</code> may run.
					</label>
				</p>
				<?php foreach ( $by_category as $category => $tools ) : ?>
					<h2><?php echo esc_html( ucfirst( $category ) ); ?></h2>
					<table class="widefat striped wpmcp-tools">
						<?php foreach ( $tools as $name => $tool ) : ?>
							<tr>
								<td class="wpmcp-tool-toggle">
									<label>
										<input type="checkbox" name="tool_<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! in_array( $name, $disabled, true ) ); ?>>
										<code><?php echo esc_html( $name ); ?></code>
									</label>
								</td>
								<td>
									<?php echo esc_html( $tool['description'] ); ?>
									<?php if ( $tool['write'] ) : ?>
										<span class="wpmcp-badge">write</span>
									<?php endif; ?>
									<?php if ( $tool['confirm'] ) : ?>
										<span class="wpmcp-badge wpmcp-badge-warn">confirm</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<p><button class="button button-primary" type="submit">Save Tools</button></p>
			</form>
		</div>
		<?php
	}

	public function render_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$changes = wpmcp_plugin()->change_log->list_changes( 50 );
		?>
		<div class="wrap wpmcp-wrap">
			<h1>History</h1>
			<p>Every MCP-made change, newest first. Entries marked <em>yes</em> can be undone with the <code>rollback-change</code> tool.</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th><th>When (UTC)</th><th>Domain</th><th>Action</th><th>Target</th><th>Summary</th><th>Reversible</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $changes ) ) : ?>
						<tr><td colspan="7">No changes recorded yet.</td></tr>
					<?php endif; ?>
					<?php foreach ( $changes as $change ) : ?>
						<tr>
							<td><?php echo esc_html( $change['id'] ); ?></td>
							<td><?php echo esc_html( $change['created_at'] ); ?></td>
							<td><?php echo esc_html( $change['domain'] ); ?></td>
							<td><code><?php echo esc_html( $change['action'] ); ?></code></td>
							<td><?php echo esc_html( $change['target'] ); ?></td>
							<td><?php echo esc_html( $change['summary'] ); ?></td>
							<td><?php echo $change['rolled_back'] ? 'rolled back' : ( $change['reversible'] ? 'yes' : 'no' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
