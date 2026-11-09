<?php
/**
 * Plugin Name: ATO Portal
 * Description: Back end for the ATO Intake and Console tools served from /tools/ato/. Stores packets, office actions and the audit trail in this site's database, uses WordPress sign-in (including the site's SSO), and gives the office a settings screen for staff access and reference tables.
 * Version: 0.3.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: UCLA ATO
 */
if (!defined('ABSPATH')) exit;
define('ATO_PORTAL_VER', '0.3.0');

/* ------------------------------------------------------------------ install */

register_activation_hook(__FILE__, 'ato_install');
add_action('admin_init', function () { if (get_option('ato_portal_db') !== ATO_PORTAL_VER) ato_install(); });

function ato_install() {
  global $wpdb; $c = $wpdb->get_charset_collate(); $p = $wpdb->prefix;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta("CREATE TABLE {$p}ato_packets (
  id varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  source varchar(20) NOT NULL DEFAULT 'intake',
  owner_id bigint(20) unsigned NOT NULL,
  owner_email varchar(190) NOT NULL DEFAULT '',
  owner_name varchar(190) NOT NULL DEFAULT '',
  bundle longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_id (owner_id),
  KEY updated_at (updated_at)
) $c;");
  dbDelta("CREATE TABLE {$p}ato_packet_updates (
  packet_id varchar(64) NOT NULL,
  status varchar(40) DEFAULT NULL,
  analyst_id varchar(190) DEFAULT NULL,
  updated_by varchar(190) DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (packet_id)
) $c;");
  dbDelta("CREATE TABLE {$p}ato_packet_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  packet_id varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'note',
  text text,
  actor varchar(190) DEFAULT NULL,
  dot varchar(20) DEFAULT NULL,
  data longtext,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY packet_id (packet_id)
) $c;");
  dbDelta("CREATE TABLE {$p}ato_signatures (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  packet_id varchar(64) NOT NULL,
  seq int(11) NOT NULL DEFAULT 0,
  role varchar(40) NOT NULL DEFAULT '',
  label varchar(100) NOT NULL DEFAULT '',
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'waiting',
  token_hash char(64) DEFAULT NULL,
  created_at datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  reminded_at datetime DEFAULT NULL,
  acted_at datetime DEFAULT NULL,
  signed_name varchar(190) DEFAULT NULL,
  note text,
  ip varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY packet_id (packet_id),
  KEY token_hash (token_hash)
) $c;");
  dbDelta("CREATE TABLE {$p}ato_roster (
  empid varchar(20) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  title varchar(10) NOT NULL DEFAULT '',
  dept varchar(120) NOT NULL DEFAULT '',
  end_date date DEFAULT NULL,
  src varchar(80) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  uploaded_at datetime NOT NULL,
  PRIMARY KEY  (empid),
  KEY end_date (end_date)
) $c;");
  dbDelta("CREATE TABLE {$p}ato_reminders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref varchar(80) NOT NULL,
  end_date date NOT NULL,
  band int(11) NOT NULL,
  sent_at datetime NOT NULL,
  recipients text,
  result varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY ref_end_band (ref,end_date,band)
) $c;");
  if (!wp_next_scheduled('ato_daily')) wp_schedule_event(time() + 600, 'daily', 'ato_daily');
  if (!get_role('ato_staff')) add_role('ato_staff', 'ATO staff', ['read' => true, 'ato_work' => true]);
  $admin = get_role('administrator');
  if ($admin) { $admin->add_cap('ato_work'); $admin->add_cap('ato_manage'); }
  if (get_option('ato_portal_settings') === false) add_option('ato_portal_settings', ato_settings_defaults(), '', false);
  update_option('ato_portal_db', ATO_PORTAL_VER, false);
}

/* ------------------------------------------------------------------ settings */

function ato_settings_defaults() {
  return [
    'password_login' => 1,
    'signup'         => 0,
    'signup_domains' => 'ucla.edu, mednet.ucla.edu',
    'sso'            => 0,
    'sso_label'      => 'Sign in with UCLA Logon',
    'sso_url'        => '',
    'pilot_mode'     => 1,
    'sample_packets' => 1,
    // Email (wp_mail). Off until the office turns it on.
    'email_on'       => 0,
    'office_inbox'   => '',
    'from_name'      => 'ATO Portal',
    'from_address'   => '',
    'email_redirect' => '',
    'email_domains'  => 'ucla.edu, mednet.ucla.edu',
    'intake_url'     => '',
    'console_url'    => '',
    'n_new'          => 1,
    'n_receipt'      => 1,
    'n_returned'     => 1,
    'n_status'       => 1,
    'n_assign'       => 1,
    'n_console'      => 1,
    'approve_url'    => '',
    // Reappointment reminders (daily job)
    'reappt_on'      => 1,
    'reminder_to'    => '',
    'reminder_names' => 0,
    'test_today'     => '',
  ];
}
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('ato_daily'); });
add_action('ato_daily', 'ato_run_daily');
function ato_settings() { return wp_parse_args((array) get_option('ato_portal_settings', []), ato_settings_defaults()); }

// Built-in reference tables. Must match REF in Intake.dc.html; the office overrides them under ATO Portal → Content.
function ato_ref_defaults() {
  return [
    'nrsaEffective' => '2025-10-01', 'nrsaSource' => 'NIH NOT-OD-26-044 (FY2026)',
    'nrsaPostdoc'   => [63480, 63900, 64380, 66948, 69180, 71748, 74424, 77076],
    'nrsaPredoc'    => 29364,
    'postdocScale'  => [
      ['from' => '2025-10-01', 'lv' => [69073, 71632, 74281, 77030, 79881, 82836]],
      ['from' => '2026-10-01', 'lv' => [71491, 74140, 76881, 79727, 82677, 85736]],
    ],
    'postdocSource' => 'UC Table 23 (Oct-2026 scales)',
    'gsrScale'      => [
      ['from' => '2025-10-01', 'pt' => [2995.63, 3227.79, 3477.96, 3747.54, 4038.00, 4350.96]],
      ['from' => '2026-10-01', 'pt' => [3174.00, 3357.00, 3613.00, 3888.00, 4185.00, 4480.00]],
    ],
    'gsrSource'     => 'UC Table 22 (Oct-2026 scales)',
  ];
}
function ato_content() { return wp_parse_args((array) get_option('ato_portal_content', []), ['ref' => null, 'updated_at' => '', 'updated_by' => '']); }
function ato_ref_effective() { $c = ato_content(); return is_array($c['ref']) ? $c['ref'] : ato_ref_defaults(); }

// Shape check against the defaults: same keys, same array lengths, positive numbers, valid dates.
function ato_validate_ref($in, &$err) {
  $def = ato_ref_defaults(); $out = [];
  foreach ($def as $k => $d) {
    if (!array_key_exists($k, $in)) { $err = "Missing “$k”."; return null; }
    $v = $in[$k];
    if (is_string($d)) {
      if (!is_string($v)) { $err = "“$k” must be text."; return null; }
      $v = sanitize_text_field($v);
      if (preg_match('/Effective$/', $k) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $err = "“$k” must be a date (YYYY-MM-DD)."; return null; }
      $out[$k] = $v;
    } elseif (is_numeric($d)) {
      if (!is_numeric($v) || $v <= 0) { $err = "“$k” must be a positive number."; return null; }
      $out[$k] = $v + 0;
    } elseif (isset($d[0]) && is_numeric($d[0])) {
      if (!is_array($v) || count($v) !== count($d)) { $err = "“$k” needs exactly " . count($d) . " values."; return null; }
      foreach ($v as $n) if (!is_numeric($n) || $n <= 0) { $err = "Every value in “$k” must be a positive number."; return null; }
      $out[$k] = array_map(function ($n) { return $n + 0; }, array_values($v));
    } else {
      $ak = array_values(array_diff(array_keys($d[0]), ['from']))[0]; $len = count($d[0][$ak]);
      if (!is_array($v) || !count($v)) { $err = "“$k” needs at least one effective-dated row."; return null; }
      $rows = [];
      foreach ($v as $row) {
        if (!is_array($row) || !isset($row['from']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['from'])) { $err = "Every row in “$k” needs an effective date (YYYY-MM-DD)."; return null; }
        if (!isset($row[$ak]) || !is_array($row[$ak]) || count($row[$ak]) !== $len) { $err = "Every row in “$k” needs exactly $len values."; return null; }
        foreach ($row[$ak] as $n) if (!is_numeric($n) || $n <= 0) { $err = "Every value in “$k” must be a positive number."; return null; }
        $rows[] = ['from' => $row['from'], $ak => array_map(function ($n) { return $n + 0; }, array_values($row[$ak]))];
      }
      usort($rows, function ($a, $b) { return strcmp($a['from'], $b['from']); });
      $out[$k] = $rows;
    }
  }
  return $out;
}

/* ------------------------------------------------------------------ helpers */

function ato_now() { return gmdate('Y-m-d H:i:s'); }
function ato_iso($dt) { return $dt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC')) : null; }
function ato_is_staff() { return current_user_can('ato_work'); }
function ato_me() {
  $u = wp_get_current_user(); if (!$u || !$u->ID) return null;
  return ['id' => (string) $u->ID, 'email' => strtolower($u->user_email), 'name' => $u->display_name ?: $u->user_email,
          'dept' => (string) get_user_meta($u->ID, 'ato_department', true)];
}
function ato_valid_id($id) { return is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id); }
// Decode the raw request body without turning {} into [] (packets carry empty objects).
function ato_body($req) { $b = json_decode($req->get_body()); return is_object($b) ? $b : new stdClass(); }
function ato_ids_param($req) {
  $raw = (string) $req->get_param('ids'); if ($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw)), 'ato_valid_id'));
}
function ato_in($ids) { global $wpdb; return implode(',', array_map(function ($i) use ($wpdb) { return $wpdb->prepare('%s', $i); }, $ids)); }
function ato_owns($pid) {
  global $wpdb; $me = get_current_user_id(); if (!$me) return false;
  return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}ato_packets WHERE id=%s AND owner_id=%d", $pid, $me));
}
function ato_packet_out($r) {
  return ['id' => $r->id, 'created_at' => ato_iso($r->created_at), 'updated_at' => ato_iso($r->updated_at), 'source' => $r->source,
          'owner_id' => (string) $r->owner_id, 'owner_email' => $r->owner_email, 'owner_name' => $r->owner_name, 'bundle' => json_decode($r->bundle)];
}
function ato_update_out($r) { return ['packet_id' => $r->packet_id, 'status' => $r->status, 'analyst_id' => $r->analyst_id, 'updated_by' => $r->updated_by, 'updated_at' => ato_iso($r->updated_at)]; }
function ato_event_out($r) {
  return ['id' => (int) $r->id, 'packet_id' => $r->packet_id, 'created_at' => ato_iso($r->created_at), 'kind' => $r->kind, 'text' => $r->text,
          'actor' => $r->actor, 'dot' => $r->dot, 'data' => $r->data === null ? null : json_decode($r->data), 'actor_id' => (string) $r->actor_id];
}
function ato_limit_hit($bucket, $max, $window) {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $k = 'ato_rl_' . $bucket . '_' . md5($ip);
  $n = (int) get_transient($k); if ($n >= $max) return true; set_transient($k, $n + 1, $window); return false;
}

/* ------------------------------------------------------------------ sign-in (admin-ajax: it honours the login cookie without a nonce) */

function ato_ajax_fail($msg, $code = 400) { wp_send_json_error(['message' => $msg], $code); }
function ato_check_login_nonce() { if (!check_ajax_referer('ato_login', '_n', false)) ato_ajax_fail('This page has been open too long. Reload it and try again.', 403); }

foreach (['ato_session', 'ato_login', 'ato_signup', 'ato_reset'] as $a) { add_action("wp_ajax_$a", "ato_ajax_" . substr($a, 4)); add_action("wp_ajax_nopriv_$a", "ato_ajax_" . substr($a, 4)); }
add_action('wp_ajax_ato_logout', 'ato_ajax_logout');

function ato_ajax_session() {
  nocache_headers(); $s = ato_settings();
  wp_send_json_success([
    'user'        => ato_me(),
    'nonce'       => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
    'login_nonce' => wp_create_nonce('ato_login'),
    'caps'        => ['password' => (bool) $s['password_login'], 'signup' => (bool) ($s['password_login'] && $s['signup']), 'sso' => (bool) $s['sso'], 'ssoLabel' => $s['sso_label'], 'seed' => (bool) ($s['pilot_mode'] && $s['sample_packets'])],
    'login_url'   => $s['sso_url'] ? $s['sso_url'] : wp_login_url(),
    'logout_url'  => wp_logout_url(),
  ]);
}
function ato_ajax_login() {
  nocache_headers(); ato_check_login_nonce(); $s = ato_settings();
  if (!$s['password_login']) ato_ajax_fail('Password sign-in is turned off. Use the single sign-on button.');
  if (ato_limit_hit('login', 10, 15 * MINUTE_IN_SECONDS)) ato_ajax_fail('Too many sign-in attempts. Wait 15 minutes and try again.', 429);
  $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $pw = (string) wp_unslash($_POST['password'] ?? '');
  $u = $email ? get_user_by('email', $email) : null;
  if (!$u) ato_ajax_fail('Email or password is incorrect.');
  $r = wp_signon(['user_login' => $u->user_login, 'user_password' => $pw, 'remember' => true], is_ssl());
  if (is_wp_error($r)) ato_ajax_fail('Email or password is incorrect.');
  wp_send_json_success(['ok' => true]);
}
function ato_ajax_signup() {
  nocache_headers(); ato_check_login_nonce(); $s = ato_settings();
  if (!$s['password_login'] || !$s['signup']) ato_ajax_fail('New accounts are created by the ATO office. Ask the office to add you.');
  if (ato_limit_hit('signup', 5, HOUR_IN_SECONDS)) ato_ajax_fail('Too many attempts. Try again later.', 429);
  $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? ''))); $pw = (string) wp_unslash($_POST['password'] ?? '');
  $name = sanitize_text_field(wp_unslash($_POST['full_name'] ?? '')); $dept = sanitize_text_field(wp_unslash($_POST['department'] ?? ''));
  if (!is_email($email)) ato_ajax_fail('Enter a valid email address.');
  $domains = array_filter(array_map(function ($d) { return strtolower(trim($d)); }, explode(',', $s['signup_domains'])));
  $host = substr(strrchr($email, '@'), 1);
  if ($domains && !in_array($host, $domains, true)) ato_ajax_fail('Use your ' . implode(' or ', array_map(function ($d) { return '@' . $d; }, $domains)) . ' email address.');
  if (strlen($pw) < 10) ato_ajax_fail('Use a password of at least 10 characters.');
  if (email_exists($email)) ato_ajax_fail('An account with that email already exists. Sign in instead.');
  $id = wp_insert_user(['user_login' => $email, 'user_email' => $email, 'user_pass' => $pw, 'display_name' => $name ?: $email, 'first_name' => $name, 'role' => 'subscriber']);
  if (is_wp_error($id)) ato_ajax_fail($id->get_error_message());
  if ($dept) update_user_meta($id, 'ato_department', $dept);
  wp_signon(['user_login' => $email, 'user_password' => $pw, 'remember' => true], is_ssl());
  wp_send_json_success(['ok' => true]);
}
function ato_ajax_reset() {
  nocache_headers(); ato_check_login_nonce(); $s = ato_settings();
  if (!$s['password_login']) ato_ajax_fail('Password sign-in is turned off.');
  if (ato_limit_hit('reset', 5, HOUR_IN_SECONDS)) ato_ajax_fail('Too many attempts. Try again later.', 429);
  $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $u = $email ? get_user_by('email', $email) : null;
  if ($u) retrieve_password($u->user_login);
  wp_send_json_success(['ok' => true]); // same answer either way, so the form can't be used to probe for accounts
}
function ato_ajax_logout() {
  if (!check_ajax_referer('wp_rest', '_n', false)) ato_ajax_fail('Reload the page and try again.', 403);
  wp_logout(); wp_send_json_success(['ok' => true]);
}

// Office-added staff who haven't signed in yet get the role on their first sign-in (works with SSO auto-provisioning).
function ato_apply_pending($user) {
  if (!$user || !$user->ID) return; $pend = (array) get_option('ato_pending_staff', []); $e = strtolower($user->user_email);
  if (!isset($pend[$e])) return;
  $user->add_role('ato_staff'); if (!empty($pend[$e]['manage'])) $user->add_cap('ato_manage');
  if (!empty($pend[$e]['name']) && ($user->display_name === $user->user_login || $user->display_name === $user->user_email)) wp_update_user(['ID' => $user->ID, 'display_name' => $pend[$e]['name']]);
  unset($pend[$e]); update_option('ato_pending_staff', $pend, false);
}
add_action('user_register', function ($id) { ato_apply_pending(get_userdata($id)); });
add_action('wp_login', function ($login, $user) { ato_apply_pending($user); }, 10, 2);

/* ------------------------------------------------------------------ REST API: /wp-json/ato/v1/ */

function ato_perm_signed_in() { return is_user_logged_in() ? true : new WP_Error('ato_signed_out', 'Sign in to continue.', ['status' => 401]); }
function ato_perm_staff() { if (!is_user_logged_in()) return ato_perm_signed_in(); return ato_is_staff() ? true : new WP_Error('ato_forbidden', 'ATO office staff only.', ['status' => 403]); }
function ato_perm_manage() { if (!is_user_logged_in()) return ato_perm_signed_in(); return current_user_can('ato_manage') ? true : new WP_Error('ato_forbidden', 'Only ATO content managers can change this.', ['status' => 403]); }

add_filter('rest_post_dispatch', function ($res, $server, $req) {
  if (strpos($req->get_route(), '/ato/v1') === 0) { $res->header('Cache-Control', 'no-store, private'); $res->header('X-Robots-Tag', 'noindex'); }
  return $res;
}, 10, 3);

add_action('rest_api_init', function () {
  $ns = 'ato/v1';
  register_rest_route($ns, '/staff/me', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function () {
    if (!ato_is_staff()) return rest_ensure_response(null); $m = ato_me();
    return ['email' => $m['email'], 'full_name' => $m['name'], 'manage' => current_user_can('ato_manage')];
  }]);
  register_rest_route($ns, '/staff', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function () {
    $out = [];
    foreach (get_users(['capability' => 'ato_work', 'orderby' => 'display_name']) as $u) $out[] = ['email' => strtolower($u->user_email), 'full_name' => $u->display_name ?: $u->user_email];
    return $out;
  }]);
  register_rest_route($ns, '/config', [
    ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function () { $c = ato_content(); return ['ref' => is_array($c['ref']) ? $c['ref'] : null, 'updated_at' => $c['updated_at']]; }],
    ['methods' => 'POST', 'permission_callback' => 'ato_perm_manage', 'callback' => function ($req) {
      $b = json_decode($req->get_body(), true); $err = '';
      $ref = ato_validate_ref(is_array($b) && isset($b['ref']) ? $b['ref'] : [], $err);
      if (!$ref) return new WP_Error('ato_invalid', $err, ['status' => 400]);
      ato_save_ref($ref); return ['ok' => true];
    }],
  ]);

  register_rest_route($ns, '/packets', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
    global $wpdb; $t = $wpdb->prefix . 'ato_packets';
    $mine = $req->get_param('mine') || !ato_is_staff();
    $rows = $mine ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE owner_id=%d ORDER BY updated_at DESC", get_current_user_id()))
                  : $wpdb->get_results("SELECT * FROM $t ORDER BY updated_at DESC LIMIT 2000");
    return array_map('ato_packet_out', $rows);
  }]);
  register_rest_route($ns, '/packets/(?P<id>[A-Za-z0-9._-]{1,64})', ['methods' => 'POST', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
    global $wpdb; $t = $wpdb->prefix . 'ato_packets'; $id = $req['id'];
    if (strlen($req->get_body()) > 2 * 1024 * 1024) return new WP_Error('ato_too_big', 'This packet is too large to save.', ['status' => 413]);
    $b = ato_body($req); if (!isset($b->bundle) || !is_object($b->bundle)) return new WP_Error('ato_invalid', 'Missing packet contents.', ['status' => 400]);
    $json = wp_json_encode($b->bundle); $me = ato_me(); $now = ato_now();
    $row = $wpdb->get_row($wpdb->prepare("SELECT owner_id FROM $t WHERE id=%s", $id));
    if ($row) {
      if ((int) $row->owner_id !== (int) $me['id'] && !ato_is_staff()) return new WP_Error('ato_forbidden', 'That packet belongs to someone else.', ['status' => 403]);
      $wpdb->update($t, ['bundle' => $json, 'updated_at' => $now], ['id' => $id]);
    } else {
      $wpdb->insert($t, ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'source' => 'intake', 'owner_id' => (int) $me['id'], 'owner_email' => $me['email'], 'owner_name' => $me['name'], 'bundle' => $json]);
    }
    if ($wpdb->last_error) return new WP_Error('ato_db', 'The packet could not be saved.', ['status' => 500]);
    return ['ok' => true];
  }]);

  register_rest_route($ns, '/updates', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
    global $wpdb; $u = $wpdb->prefix . 'ato_packet_updates'; $p = $wpdb->prefix . 'ato_packets'; $ids = ato_ids_param($req);
    $where = $ids ? 'u.packet_id IN (' . ato_in($ids) . ')' : '1=1';
    $sql = ato_is_staff() ? "SELECT u.* FROM $u u WHERE $where"
                          : $wpdb->prepare("SELECT u.* FROM $u u JOIN $p p ON p.id=u.packet_id WHERE p.owner_id=%d AND $where", get_current_user_id());
    return array_map('ato_update_out', $wpdb->get_results($sql));
  }]);
  register_rest_route($ns, '/updates/(?P<id>[A-Za-z0-9._-]{1,64})', ['methods' => 'POST', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
    global $wpdb; $t = $wpdb->prefix . 'ato_packet_updates'; $id = $req['id']; $b = ato_body($req); $staff = ato_is_staff(); $me = ato_me();
    $set = [];
    if ($staff) {
      if (property_exists($b, 'status'))     $set['status'] = $b->status === null ? null : sanitize_key($b->status);
      if (property_exists($b, 'analyst_id')) $set['analyst_id'] = $b->analyst_id === null ? null : sanitize_text_field($b->analyst_id);
      $set['updated_by'] = isset($b->updated_by) && $b->updated_by ? sanitize_text_field($b->updated_by) : $me['name'];
    } else {
      // A submitter can only (re)submit or open an inquiry on their own packet.
      if (!ato_owns($id)) return new WP_Error('ato_forbidden', 'That packet belongs to someone else.', ['status' => 403]);
      $st = isset($b->status) ? sanitize_key($b->status) : '';
      if (!in_array($st, ['submitted', 'inquiry'], true)) return new WP_Error('ato_forbidden', 'Only the ATO office can set that status.', ['status' => 403]);
      $set = ['status' => $st, 'updated_by' => $me['name']];
      if ($st === 'submitted') ato_route_retire($id);
    }
    $set['updated_at'] = ato_now();
    $prev = $wpdb->get_row($wpdb->prepare("SELECT status, analyst_id FROM $t WHERE packet_id=%s", $id));
    if ($prev) $wpdb->update($t, $set, ['packet_id' => $id]);
    else $wpdb->insert($t, array_merge(['packet_id' => $id], $set));
    ato_notify_update($id, $prev, $set, $staff, $me);
    if ($staff && array_key_exists('status', $set)) ato_route_on_status($id, $prev ? $prev->status : null, $set['status']);
    return ['ok' => true];
  }]);

  register_rest_route($ns, '/events', [
    ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
      global $wpdb; $e = $wpdb->prefix . 'ato_packet_events'; $p = $wpdb->prefix . 'ato_packets'; $ids = ato_ids_param($req);
      $where = $ids ? 'e.packet_id IN (' . ato_in($ids) . ')' : '1=1';
      $sql = ato_is_staff() ? "SELECT e.* FROM $e e WHERE $where ORDER BY e.id ASC"
                            : $wpdb->prepare("SELECT e.* FROM $e e JOIN $p p ON p.id=e.packet_id WHERE p.owner_id=%d AND $where ORDER BY e.id ASC", get_current_user_id());
      return array_map('ato_event_out', $wpdb->get_results($sql));
    }],
    ['methods' => 'POST', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
      global $wpdb; $b = ato_body($req); $pid = isset($b->packet_id) ? (string) $b->packet_id : ''; $me = ato_me(); $staff = ato_is_staff();
      if (!ato_valid_id($pid)) return new WP_Error('ato_invalid', 'Missing packet id.', ['status' => 400]);
      if (!$staff && !ato_owns($pid)) return new WP_Error('ato_forbidden', 'That packet belongs to someone else.', ['status' => 403]);
      $kinds = ['submit', 'note', 'status', 'assign', 'email', 'reminder', 'override', 'exec', 'return'];
      $kind = isset($b->kind) && in_array($b->kind, $kinds, true) ? $b->kind : 'note';
      $wpdb->insert($wpdb->prefix . 'ato_packet_events', [
        'packet_id' => $pid, 'created_at' => ato_now(), 'kind' => $kind,
        'text' => isset($b->text) ? mb_substr(sanitize_textarea_field((string) $b->text), 0, 5000) : '',
        'actor' => $staff && !empty($b->actor) ? sanitize_text_field($b->actor) : $me['name'],
        'dot' => isset($b->dot) && $b->dot ? sanitize_key($b->dot) : null,
        'data' => isset($b->data) && $b->data !== null ? wp_json_encode($b->data) : null,
        'actor_id' => (int) $me['id'],
      ]);
      $mail = null;
      if ($kind === 'return') ato_notify_return($pid, isset($b->data->note) ? (string) $b->data->note : (string) ($b->text ?? ''), $me);
      if ($staff && in_array($kind, ['email', 'reminder'], true) && isset($b->data->to_email)) $mail = ato_send_composed($pid, $b->data, $kind);
      return $mail === null ? ['ok' => true] : ['ok' => true, 'mail' => $mail];
    }],
  ]);

  // Approval page (no sign-in: the emailed link carries a single-use token).
  register_rest_route($ns, '/approval', [
    ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
      nocache_headers();
      if (ato_limit_hit('approve_get', 120, 15 * MINUTE_IN_SECONDS)) return new WP_Error('ato_slow', 'Too many requests. Wait a few minutes and try again.', ['status' => 429]);
      $row = ato_sig_by_token((string) $req->get_param('t'));
      if (!$row || in_array($row->status, ['cancelled', 'waiting', 'missing'], true)) return ['state' => 'invalid'];
      $state = $row->status === 'sent' ? (ato_sig_expired($row) ? 'expired' : 'open') : 'done';
      $out = ['state' => $state, 'signer' => ['name' => $row->name, 'label' => $row->label, 'email' => $row->email],
              'decision' => ['status' => $row->status, 'at' => ato_iso($row->acted_at), 'signed_name' => $row->signed_name, 'note' => $row->note]];
      if ($state !== 'expired') { $out['packet'] = ato_approval_view($row->packet_id); $out['route'] = ato_route_view($row->packet_id, $row->id); }
      return $out;
    }],
    ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
      nocache_headers();
      if (ato_limit_hit('approve_post', 30, 15 * MINUTE_IN_SECONDS)) return new WP_Error('ato_slow', 'Too many attempts. Wait a few minutes and try again.', ['status' => 429]);
      $b = json_decode($req->get_body(), true); $b = is_array($b) ? $b : [];
      $row = ato_sig_by_token((string) ($b['t'] ?? ''));
      if (!$row || $row->status !== 'sent') return new WP_Error('ato_gone', 'This link is no longer valid. It may have been used already, or replaced by a newer email.', ['status' => 410]);
      if (ato_sig_expired($row)) return new WP_Error('ato_gone', 'This link has expired. Ask the ATO office to send a new one.', ['status' => 410]);
      $dec = (string) ($b['decision'] ?? ''); $name = sanitize_text_field((string) ($b['name'] ?? '')); $note = mb_substr(sanitize_textarea_field((string) ($b['note'] ?? '')), 0, 2000);
      if (!in_array($dec, ['approve', 'changes'], true)) return new WP_Error('ato_invalid', 'Choose Approve or Request changes.', ['status' => 400]);
      if (mb_strlen($name) < 3) return new WP_Error('ato_invalid', 'Type your full name.', ['status' => 400]);
      if ($dec === 'changes' && $note === '') return new WP_Error('ato_invalid', 'Say what needs to change.', ['status' => 400]);
      ato_route_decide($row, $dec, $name, $note);
      return ['ok' => true, 'state' => 'done'];
    }],
  ]);
  register_rest_route($ns, '/signatures', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function ($req) {
    global $wpdb; $t = ato_sig_table(); $p = $wpdb->prefix . 'ato_packets'; $ids = ato_ids_param($req);
    $where = ($ids ? 's.packet_id IN (' . ato_in($ids) . ')' : '1=1') . " AND s.status NOT IN ('cancelled','superseded')";
    $sql = ato_is_staff() ? "SELECT s.* FROM $t s WHERE $where ORDER BY s.packet_id, s.seq, s.id"
                          : $wpdb->prepare("SELECT s.* FROM $t s JOIN $p p ON p.id=s.packet_id WHERE p.owner_id=%d AND $where ORDER BY s.packet_id, s.seq, s.id", get_current_user_id());
    return array_map('ato_sig_out', $wpdb->get_results($sql));
  }]);
  register_rest_route($ns, '/signatures/(?P<id>\d+)', ['methods' => 'POST', 'permission_callback' => 'ato_perm_staff', 'callback' => function ($req) {
    global $wpdb; $t = ato_sig_table(); $me = ato_me();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", (int) $req['id']));
    if (!$row) return new WP_Error('ato_missing', 'That approval step no longer exists.', ['status' => 404]);
    if (!in_array($row->status, ['sent', 'missing', 'waiting'], true)) return new WP_Error('ato_closed', 'That approval is already closed.', ['status' => 409]);
    $b = json_decode($req->get_body(), true); $b = is_array($b) ? $b : []; $act = (string) ($b['action'] ?? ''); $who = trim($row->name) ?: $row->label;
    if (!empty($b['email'])) {
      $e = strtolower(sanitize_email((string) $b['email'])); if (!is_email($e)) return new WP_Error('ato_invalid', 'Enter a valid email address.', ['status' => 400]);
      $wpdb->update($t, ['email' => $e], ['id' => $row->id]); $row->email = $e;
      ato_insert_event($row->packet_id, 'note', "Approver email for $who ({$row->label}) set to $e by {$me['name']}", '#8195a3');
    }
    if ($act === 'resend') {
      if ($row->status === 'waiting') return new WP_Error('ato_invalid', 'This approver is not up yet. Earlier approvers go first.', ['status' => 400]);
      return ['ok' => true, 'mail' => ato_route_send($row, $row->status === 'sent' && !ato_sig_expired($row))];
    }
    if ($act === 'link') {
      if ($row->status === 'waiting') return new WP_Error('ato_invalid', 'This approver is not up yet. Earlier approvers go first.', ['status' => 400]);
      $tok = ato_new_token($row->id);
      $wpdb->update($t, ['status' => 'sent', 'sent_at' => ($row->sent_at && !ato_sig_expired($row)) ? $row->sent_at : ato_now()], ['id' => $row->id]);
      ato_insert_event($row->packet_id, 'note', "Approval link for $who ({$row->label}) copied by {$me['name']} to share directly", '#8195a3');
      return ['ok' => true, 'url' => ato_approve_url($tok)];
    }
    if ($act === 'offline') {
      $note = mb_substr(sanitize_textarea_field((string) ($b['note'] ?? '')), 0, 2000);
      if ($note === '') return new WP_Error('ato_invalid', 'Say how and when the approval was given.', ['status' => 400]);
      ato_route_decide($row, 'approve', 'Recorded by ' . $me['name'], $note, $me['name']);
      return ['ok' => true];
    }
    return new WP_Error('ato_invalid', 'Unknown action.', ['status' => 400]);
  }]);

  register_rest_route($ns, '/roster', ['methods' => 'GET', 'permission_callback' => 'ato_perm_signed_in', 'callback' => function () {
    global $wpdb; $m = (array) get_option('ato_roster_meta', []);
    $staff = ato_is_staff();
    // Department administrators get identity and appointment facts only; pay rates stay with ATO staff.
    $rows = array_map(function ($r) use ($staff) { $d = json_decode($r->data, true);
      if (!$staff && !empty($d['lines'])) $d['lines'] = array_map(function ($l) { unset($l['annual'], $l['monthly']); return $l; }, $d['lines']);
      return $d; }, $wpdb->get_results("SELECT data FROM {$wpdb->prefix}ato_roster ORDER BY name"));
    return ['rows' => $rows, 'count' => count($rows), 'updated_at' => isset($m['at']) ? ato_iso($m['at']) : null];
  }]);

  register_rest_route($ns, '/reset', ['methods' => 'POST', 'permission_callback' => 'ato_perm_staff', 'callback' => function () {
    if (!ato_settings()['pilot_mode']) return new WP_Error('ato_forbidden', 'Reset is only available while the portal is in pilot mode (ATO Portal → Data).', ['status' => 403]);
    ato_wipe(); return ['ok' => true];
  }]);
});

/* ------------------------------------------------------------------ email (wp_mail) */

function ato_status_label($st) {
  $m = ['submitted' => 'Submitted', 'validating' => 'ATO validating', 'out_for_signature' => 'Out for signature', 'fully_signed' => 'Fully signed',
        'ato_review' => 'ATO final review', 'filed' => 'Filed', 'active' => 'Active', 'returned' => 'Returned for correction', 'closed' => 'Closed',
        'inquiry' => 'Visa inquiry', 'with_external_office' => 'With external office', 'pending_uscis' => 'Pending USCIS', 'approved' => 'Approved', 'denied' => 'Denied', 'referred' => 'Referred'];
  return isset($m[$st]) ? $m[$st] : ucfirst(str_replace('_', ' ', (string) $st));
}
// Statuses an office change emails the submitter about. Returns are emailed from the return event, which carries the note.
function ato_notify_statuses() { return ['out_for_signature', 'fully_signed', 'filed', 'active', 'closed', 'with_external_office', 'pending_uscis', 'approved', 'denied']; }
function ato_url($which) { $s = ato_settings(); $u = $s[$which . '_url']; return $u ? $u : home_url('/tools/ato/' . ($which === 'console' ? 'console' : 'intake') . '.html'); }

// Packet summary for email. Deliberately no trainee name, salary or visa detail: email is not an approved channel for that data.
function ato_brief($pid) {
  global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ato_packets WHERE id=%s", $pid)); if (!$r) return null;
  $b = json_decode($r->bundle); $p = $b && isset($b->packet) ? $b->packet : new stdClass();
  $pt = ['new_appointment' => 'New appointment', 'reappointment' => 'Reappointment', 'action_request' => 'Action request', 'visa_case' => 'Visa case', 'termination_notice' => 'Termination notice', 'referral' => 'Referral'];
  $bits = [isset($p->packet_type) && isset($pt[$p->packet_type]) ? $pt[$p->packet_type] : 'Packet'];
  if (!empty($p->form_template)) $bits[] = $p->form_template;
  if (!empty($p->title_codes) && is_array($p->title_codes)) $bits[] = implode(' + ', $p->title_codes); elseif (!empty($p->title_code)) $bits[] = $p->title_code;
  return ['label' => implode(' · ', $bits), 'owner_email' => $r->owner_email, 'owner_name' => $r->owner_name, 'start' => isset($p->appointment_start_date) ? $p->appointment_start_date : ''];
}

function ato_insert_event($pid, $kind, $text, $dot = '#8195a3', $data = null) {
  global $wpdb;
  $wpdb->insert($wpdb->prefix . 'ato_packet_events', ['packet_id' => $pid, 'created_at' => ato_now(), 'kind' => $kind, 'text' => mb_substr($text, 0, 5000),
    'actor' => 'ATO Portal', 'dot' => $dot, 'data' => $data === null ? null : wp_json_encode($data), 'actor_id' => 0]);
}

function ato_mail_allowed($email) {
  $s = ato_settings(); $d = array_filter(array_map(function ($x) { return strtolower(trim($x)); }, explode(',', $s['email_domains'])));
  if (!$d) return true; $host = strtolower(substr(strrchr($email, '@'), 1));
  foreach ($d as $ok) if ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok) return true;
  return false;
}

// Returns 'sent' | 'off' | 'blocked' | 'failed'. Every attempt goes to the mail log; $audit adds a line to the packet's audit trail.
function ato_mail($to, $subject, $body, $pid = '', $trigger = 'manual', $audit = true) {
  $s = ato_settings(); $to = array_values(array_unique(array_filter(array_map(function ($e) { return strtolower(sanitize_email(trim($e))); }, (array) $to), 'is_email')));
  if (!$s['email_on']) return 'off';
  if (!$to) return 'failed';
  // With a pilot redirect set, nothing reaches the real recipients, so the domain filter is not needed.
  $redirect = $s['email_redirect'] && is_email($s['email_redirect']);
  $ok_to = $redirect ? $to : array_values(array_filter($to, 'ato_mail_allowed')); $blocked = array_values(array_diff($to, $ok_to));
  $result = 'blocked';
  if ($ok_to) {
    $deliver = $ok_to; $subj = $subject;
    if ($redirect) {
      $deliver = [$s['email_redirect']]; $subj = '[TEST] ' . $subject;
      $body = "Pilot redirect: this message would have gone to " . implode(', ', $ok_to) . ".\n\n" . $body;
    }
    $h = ['Content-Type: text/plain; charset=UTF-8'];
    if ($s['from_address'] && is_email($s['from_address'])) $h[] = 'From: ' . ($s['from_name'] ?: 'ATO Portal') . ' <' . $s['from_address'] . '>';
    if ($s['office_inbox'] && is_email($s['office_inbox'])) $h[] = 'Reply-To: ' . $s['office_inbox'];
    $result = wp_mail($deliver, $subj, $body . "\n\n--\nAutomated message from the ATO Portal. Reply to reach the ATO office.", $h) ? 'sent' : 'failed';
  }
  $log = (array) get_option('ato_mail_log', []);
  array_unshift($log, ['at' => ato_now(), 'to' => implode(', ', $to), 'subject' => $subject, 'trigger' => $trigger, 'pid' => $pid, 'result' => $result, 'blocked' => implode(', ', $blocked)]);
  update_option('ato_mail_log', array_slice($log, 0, 50), false);
  if ($audit && $pid) {
    $who = implode(', ', $to);
    if ($result === 'sent') ato_insert_event($pid, 'email', 'Email sent to ' . $who . ' — "' . $subject . '"', '#a0742a', ['trigger' => $trigger]);
    elseif ($result === 'blocked') ato_insert_event($pid, 'email', 'Email to ' . $who . ' not sent — address outside the allowed domains', '#a33a2f', ['trigger' => $trigger]);
    else ato_insert_event($pid, 'email', 'Email to ' . $who . ' could not be sent', '#a33a2f', ['trigger' => $trigger]);
  }
  return $result;
}

function ato_mail_body($hello, $lines, $pid, $brief, $status, $link_label, $url) {
  $out = 'Hello' . ($hello ? ' ' . $hello : '') . ",\n\n" . implode("\n\n", (array) $lines) . "\n\nPacket: $pid\nType: {$brief['label']}";
  if ($status) $out .= "\nStatus: " . ato_status_label($status);
  return $out . "\n\n$link_label: $url";
}

function ato_notify_update($pid, $prev, $set, $staff, $me) {
  $s = ato_settings(); if (!$s['email_on']) return;
  $b = ato_brief($pid); if (!$b) return;
  $old = $prev ? $prev->status : null; $has = array_key_exists('status', $set); $new = $has ? $set['status'] : null;
  if (!$staff && $has && in_array($new, ['submitted', 'inquiry'], true)) {
    $again = $old === 'returned'; $what = $new === 'inquiry' ? 'New visa inquiry' : ($again ? 'Resubmitted' : 'New submission');
    if ($s['n_new'] && $s['office_inbox'])
      ato_mail($s['office_inbox'], "$what: {$b['label']} ($pid)", ato_mail_body('ATO office', [$b['owner_name'] . ' (' . $b['owner_email'] . ') ' . ($again ? 'resubmitted a corrected packet.' : 'submitted a packet.')], $pid, $b, $new, 'Open the console', ato_url('console')), $pid, 'new');
    if ($s['n_receipt'] && $b['owner_email'])
      ato_mail($b['owner_email'], ($again ? 'Resubmission received' : 'Submission received') . ": $pid", ato_mail_body($b['owner_name'], ['The ATO office received your packet. You will get an email when it is returned for correction or moves forward. You can follow it under My submissions.'], $pid, $b, $new, 'Open the intake', ato_url('intake')), $pid, 'receipt');
  }
  if ($staff && $has && $new !== $old && $s['n_status'] && in_array($new, ato_notify_statuses(), true) && $b['owner_email'])
    ato_mail($b['owner_email'], 'Packet update: ' . ato_status_label($new) . " ($pid)", ato_mail_body($b['owner_name'], ['The ATO office moved your packet to: ' . ato_status_label($new) . '.'], $pid, $b, $new, 'Open the intake', ato_url('intake')), $pid, 'status');
  if ($staff && array_key_exists('analyst_id', $set) && $s['n_assign']) {
    $a = strtolower((string) $set['analyst_id']); $was = $prev ? strtolower((string) $prev->analyst_id) : '';
    if ($a && $a !== $was && is_email($a) && $a !== $me['email']) {
      $u = get_user_by('email', $a);
      ato_mail($a, "Assigned to you: {$b['label']} ($pid)", ato_mail_body($u ? $u->display_name : '', [$me['name'] . ' assigned this packet to you.'], $pid, $b, $old, 'Open the console', ato_url('console')), $pid, 'assign');
    }
  }
}

function ato_notify_return($pid, $note, $me) {
  $s = ato_settings(); if (!$s['email_on'] || !$s['n_returned']) return;
  $b = ato_brief($pid); if (!$b || !$b['owner_email']) return;
  $note = trim(wp_strip_all_tags($note));
  ato_mail($b['owner_email'], "Returned for correction: $pid", ato_mail_body($b['owner_name'], ['Your packet was returned for correction.', 'Note from ' . $me['name'] . ":\n" . ($note ?: '(no note)'), 'Open the intake, go to My submissions, choose Open and fix, then resubmit.'], $pid, $b, 'returned', 'Open the intake', ato_url('intake')), $pid, 'returned');
}

// Emails and reminders composed in the console. The console already writes the audit line, so only failures add one here.
function ato_send_composed($pid, $d, $kind) {
  $s = ato_settings(); if (!$s['email_on'] || !$s['n_console']) return 'off';
  $to = preg_split('/[\s,;]+/', (string) $d->to_email); $subj = sanitize_text_field((string) ($d->subject ?? '')); $body = sanitize_textarea_field((string) ($d->body ?? ''));
  if (!$subj || !$body) return 'failed';
  $r = ato_mail($to, $subj, $body, $pid, 'console_' . $kind, false);
  if ($r === 'blocked') ato_insert_event($pid, 'email', 'Not delivered — ' . $d->to_email . ' is outside the allowed email domains', '#a33a2f');
  if ($r === 'failed') ato_insert_event($pid, 'email', 'Not delivered — the site could not send to ' . $d->to_email, '#a33a2f');
  return $r;
}

/* ------------------------------------------------------------------ approval route: emailed links for fund managers, PIs and mentors */
// When ATO moves a packet to Out for signature, every approver on the route after ATO review gets a row here.
// Rows with the same seq are parallel (all fund managers on the packet); each seq waits for the one before it.
// The emailed link carries a random token; only its SHA-256 hash is stored. A reminder issues a new token.

function ato_sig_table() { global $wpdb; return $wpdb->prefix . 'ato_signatures'; }
function ato_bundle_arr($pid) {
  global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ato_packets WHERE id=%s", $pid));
  if (!$r) return [null, []]; $b = json_decode($r->bundle, true); return [$r, is_array($b) ? $b : []];
}
function ato_people_map($b) { $P = []; foreach ((array) ($b['people'] ?? []) as $x) if (isset($x['id'])) $P[$x['id']] = $x; return $P; }
function ato_set_status($pid, $st, $by) {
  global $wpdb; $t = $wpdb->prefix . 'ato_packet_updates';
  $prev = $wpdb->get_row($wpdb->prepare("SELECT status, analyst_id FROM $t WHERE packet_id=%s", $pid));
  $set = ['status' => $st, 'updated_by' => $by, 'updated_at' => ato_now()];
  if ($prev) $wpdb->update($t, $set, ['packet_id' => $pid]); else $wpdb->insert($t, array_merge(['packet_id' => $pid], $set));
  return $prev;
}
function ato_sig_out($r) {
  return ['id' => (int) $r->id, 'packet_id' => $r->packet_id, 'seq' => (int) $r->seq, 'role' => $r->role, 'label' => $r->label, 'name' => $r->name, 'email' => $r->email,
          'status' => $r->status, 'sent_at' => ato_iso($r->sent_at), 'reminded_at' => ato_iso($r->reminded_at), 'acted_at' => ato_iso($r->acted_at),
          'signed_name' => $r->signed_name, 'note' => $r->note, 'expired' => $r->status === 'sent' && ato_sig_expired($r)];
}
function ato_sig_by_token($tok) {
  global $wpdb; if (!is_string($tok) || !preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
  return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ato_sig_table() . ' WHERE token_hash=%s', hash('sha256', $tok)));
}
function ato_sig_expired($row) { return $row->sent_at && strtotime($row->sent_at . ' UTC') < time() - 30 * DAY_IN_SECONDS; }
function ato_approve_url($tok) { $s = ato_settings(); $u = $s['approve_url'] ? $s['approve_url'] : home_url('/tools/ato/approve.html'); return $u . (strpos($u, '?') === false ? '?' : '&') . 't=' . $tok; }
function ato_new_token($id) { global $wpdb; $tok = bin2hex(random_bytes(24)); $wpdb->update(ato_sig_table(), ['token_hash' => hash('sha256', $tok)], ['id' => $id]); return $tok; }

function ato_route_on_status($pid, $old, $new) {
  if ($new === $old) return;
  if ($new === 'out_for_signature') { ato_route_build($pid); return; }
  if ($old === 'out_for_signature') ato_route_cancel($pid, 'Open approval links cancelled. The office moved the packet to ' . ato_status_label($new) . '.');
}
// A resubmitted packet starts clean: the previous round's decisions stay in the audit trail, not on the route.
function ato_route_retire($pid) {
  global $wpdb; $wpdb->query($wpdb->prepare('UPDATE ' . ato_sig_table() . " SET status='superseded', token_hash=NULL WHERE packet_id=%s AND status NOT IN ('cancelled','superseded')", $pid));
}
function ato_route_cancel($pid, $why) {
  global $wpdb; $t = ato_sig_table();
  $n = $wpdb->query($wpdb->prepare("UPDATE $t SET status='cancelled', token_hash=NULL WHERE packet_id=%s AND status IN ('waiting','sent','missing')", $pid));
  if ($n) ato_insert_event($pid, 'status', $why, '#8195a3');
}
function ato_route_build($pid) {
  global $wpdb; $t = ato_sig_table();
  $wpdb->query($wpdb->prepare("UPDATE $t SET status='superseded', token_hash=NULL WHERE packet_id=%s AND status IN ('approved','changes')", $pid));
  ato_route_cancel($pid, 'Earlier approval links cancelled. A new approval round started.');
  list($r, $b) = ato_bundle_arr($pid); if (!$r) return;
  $P = ato_people_map($b); $rows = [];
  foreach ((array) ($b['signature_steps'] ?? []) as $s) {
    $role = (string) ($s['role'] ?? ''); if (in_array($role, ['department_admin', 'ato_review', 'ato'], true)) continue;
    $seq = (int) ($s['step_number'] ?? 0); $label = (string) ($s['label'] ?? '');
    $who = [];
    if ($role === 'fund_manager') {
      $label = 'Fund manager';
      foreach ((array) ($b['funding_lines'] ?? []) as $f) { $id = $f['fund_manager_id'] ?? ''; if ($id && isset($P[$id])) $who[] = $P[$id]; }
    }
    if (!$who && !empty($s['signer_id']) && isset($P[$s['signer_id']])) $who[] = $P[$s['signer_id']];
    if (!$who) $who[] = ['full_name' => '', 'email' => ''];
    if ($label === '') $label = ['principal_investigator' => 'Principal investigator', 'faculty_mentor' => 'Faculty mentor'][$role] ?? ucwords(str_replace('_', ' ', $role));
    $seen = [];
    foreach ($who as $p) {
      $e = strtolower(trim((string) ($p['email'] ?? ''))); $k = $e !== '' ? $e : 'n:' . ($p['full_name'] ?? '') . ':' . count($rows);
      if (isset($seen[$k])) continue; $seen[$k] = 1;
      $rows[] = ['packet_id' => $pid, 'seq' => $seq, 'role' => $role, 'label' => $label, 'name' => (string) ($p['full_name'] ?? ''), 'email' => $e, 'status' => 'waiting', 'created_at' => ato_now()];
    }
  }
  if (!$rows) { ato_insert_event($pid, 'status', 'No approvers on this route. ATO can move it to Fully signed.', '#a0742a'); return; }
  foreach ($rows as $row) $wpdb->insert($t, $row);
  ato_insert_event($pid, 'status', 'Approval round started: ' . count($rows) . ' approver' . (count($rows) === 1 ? '' : 's') . ', in route order', '#2774ae');
  ato_route_advance($pid);
}
function ato_route_advance($pid) {
  global $wpdb; $t = ato_sig_table();
  $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE packet_id=%s AND status NOT IN ('cancelled','superseded') ORDER BY seq, id", $pid));
  if (!$rows) return;
  $open = array_values(array_filter($rows, function ($x) { return $x->status !== 'approved'; }));
  if (!$open) { ato_route_complete($pid); return; }
  $seq = (int) $open[0]->seq;
  foreach ($open as $x) if ((int) $x->seq === $seq && $x->status === 'waiting') ato_route_send($x, false);
}
function ato_route_send($row, $reminder) {
  global $wpdb; $t = ato_sig_table(); $who = trim($row->name) ?: $row->label;
  if (!is_email($row->email)) {
    $wpdb->update($t, ['status' => 'missing'], ['id' => $row->id]);
    ato_insert_event($row->packet_id, 'status', "No email address for $who ({$row->label}). Add one in the console, or record the approval there.", '#a33a2f');
    return 'missing';
  }
  $tok = ato_new_token($row->id); $now = ato_now();
  $wpdb->update($t, $reminder ? ['status' => 'sent', 'reminded_at' => $now] : ['status' => 'sent', 'sent_at' => $now, 'reminded_at' => null], ['id' => $row->id]);
  $b = ato_brief($row->packet_id);
  $subj = ($reminder ? 'Reminder: approval requested' : 'Approval requested') . ": {$b['label']} ({$row->packet_id})";
  $body = 'Hello' . ($row->name ? ' ' . $row->name : '') . ",\n\nThe ATO office asks for your approval as {$row->label} on an appointment packet submitted by {$b['owner_name']}.\n\nPacket: {$row->packet_id}\nType: {$b['label']}\n\nReview and approve or request changes:\n" . ato_approve_url($tok)
        . "\n\nThis link is personal to you and can be used once. It expires 30 days after the first request." . ($reminder ? ' Links in earlier emails about this packet no longer work.' : '');
  $r = ato_mail($row->email, $subj, $body, $row->packet_id, $reminder ? 'approval_reminder' : 'approval', false);
  $tail = ['sent' => '', 'off' => ' Email is off, so copy the link from the console.', 'blocked' => ' Not delivered: the address is outside the allowed domains.', 'failed' => ' The email could not be sent.'][$r] ?? '';
  ato_insert_event($row->packet_id, $reminder ? 'reminder' : 'status', ($reminder ? 'Approval reminder sent to ' : 'Approval requested from ') . "$who ({$row->label})." . $tail, $r === 'sent' ? '#2774ae' : '#a33a2f');
  return $r;
}
function ato_route_complete($pid) {
  $prev = ato_set_status($pid, 'fully_signed', 'Approval route');
  ato_insert_event($pid, 'status', 'All approvals received. Moved to Fully signed.', '#2e7d4f');
  ato_notify_update($pid, $prev, ['status' => 'fully_signed'], true, ['name' => 'Approval route', 'email' => '']);
  $s = ato_settings(); $b = ato_brief($pid);
  if ($s['email_on'] && $s['office_inbox'] && $b) ato_mail($s['office_inbox'], "All approvals in: {$b['label']} ($pid)", ato_mail_body('ATO office', ['Every approver on the route has approved. The packet is ready for ATO final review.'], $pid, $b, 'fully_signed', 'Open the console', ato_url('console')), $pid, 'approvals_done', false);
}
function ato_route_decide($row, $dec, $signed, $note, $by_staff = '') {
  global $wpdb; $t = ato_sig_table(); $who = trim($row->name) ?: $row->label;
  $wpdb->update($t, ['status' => $dec === 'approve' ? 'approved' : 'changes', 'acted_at' => ato_now(), 'signed_name' => $signed, 'note' => $note,
                     'ip' => $by_staff ? '' : substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)], ['id' => $row->id]);
  if ($dec === 'approve') {
    $txt = $by_staff ? "Approval for $who ({$row->label}) recorded by $by_staff: \u{201C}$note\u{201D}" : "Approved by $who ({$row->label}), signed \u{201C}$signed\u{201D}" . ($note !== '' ? " with note \u{201C}$note\u{201D}" : '');
    ato_insert_event($row->packet_id, 'status', $txt, '#2e7d4f', ['sig' => (int) $row->id]);
    ato_route_advance($row->packet_id);
    return;
  }
  ato_insert_event($row->packet_id, 'return', "Changes requested by $who ({$row->label}): \u{201C}$note\u{201D}", '#a0742a', ['note' => $note, 'sig' => (int) $row->id]);
  ato_route_cancel($row->packet_id, 'Other open approval links cancelled.');
  ato_set_status($row->packet_id, 'returned', $who);
  ato_notify_return($row->packet_id, $note, ['name' => "$who ({$row->label})"]);
  $s = ato_settings(); $b = ato_brief($row->packet_id);
  if ($s['email_on'] && $s['office_inbox'] && $b) ato_mail($s['office_inbox'], "Changes requested: {$b['label']} ({$row->packet_id})", ato_mail_body('ATO office', ["$who ({$row->label}) asked for changes. The packet went back to {$b['owner_name']}.", "Note:\n$note"], $row->packet_id, $b, 'returned', 'Open the console', ato_url('console')), $row->packet_id, 'approval_changes', false);
}
// What the approver sees. They are an approver of record on this packet, so they see its substance.
function ato_approval_view($pid) {
  list($r, $b) = ato_bundle_arr($pid); if (!$r) return null;
  $p = (array) ($b['packet'] ?? []); $P = ato_people_map($b); $tr = (array) ($b['trainee'] ?? []);
  $nm = function ($id) use ($P) { return $id && isset($P[$id]) ? (string) $P[$id]['full_name'] : ''; };
  $pt = ['new_appointment' => 'New appointment', 'reappointment' => 'Reappointment', 'action_request' => 'Action request', 'visa_case' => 'Visa case', 'termination_notice' => 'Termination notice'];
  $codes = !empty($p['title_codes']) ? implode(' + ', (array) $p['title_codes']) : (string) ($p['title_code'] ?? '');
  return [
    'id' => $pid, 'type' => $pt[$p['packet_type'] ?? ''] ?? 'Packet', 'form' => (string) ($p['form_template'] ?? ''), 'title' => $codes, 'action' => (string) ($p['action_type'] ?? ''),
    'trainee' => (string) ($b['traineeName'] ?? $nm($tr['person_id'] ?? '')), 'trainee_type' => (string) ($tr['trainee_type'] ?? ''), 'dept' => (string) ($tr['home_department'] ?? ''),
    'start' => (string) ($p['appointment_start_date'] ?? ''), 'end' => (string) ($p['appointment_end_date'] ?? ''), 'fte' => $p['fte_percent'] ?? null,
    'research' => (string) ($p['research_title'] ?? ''), 'pi' => $nm($p['pi_id'] ?? ''), 'mentor' => $nm($p['faculty_mentor_id'] ?? ''), 'supplement' => !empty($p['has_supplement']),
    'submitter' => $r->owner_name, 'submitter_email' => $r->owner_email, 'submitted' => (string) ($p['submitted_at'] ?? ''),
    'lines' => array_map(function ($f) use ($nm) { return ['fau' => (string) ($f['fau'] ?? ''), 'pct' => $f['percent_distribution'] ?? null, 'bucket' => (string) ($f['title_code_bucket'] ?? ''), 'start' => (string) ($f['start_date'] ?? ''), 'end' => (string) ($f['end_date'] ?? ''), 'fm' => $nm($f['fund_manager_id'] ?? '')]; }, (array) ($b['funding_lines'] ?? [])),
    'docs' => array_map(function ($a) { return ['label' => (string) ($a['label'] ?? ''), 'present' => !empty($a['present'])]; }, (array) ($b['attachments'] ?? [])),
    'checks' => array_map(function ($v) { return ['message' => (string) ($v['message'] ?? ''), 'passed' => !empty($v['passed']), 'severity' => (string) ($v['severity'] ?? '')]; }, (array) ($b['validation_results'] ?? [])),
  ];
}
function ato_route_view($pid, $me_id) {
  global $wpdb; list($r) = ato_bundle_arr($pid);
  $out = [['label' => 'Department administrator', 'name' => $r ? $r->owner_name : '', 'status' => 'submitted', 'me' => false], ['label' => 'ATO review', 'name' => 'ATO office', 'status' => 'validated', 'me' => false]];
  foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . ato_sig_table() . " WHERE packet_id=%s AND status NOT IN ('cancelled','superseded') ORDER BY seq, id", $pid)) as $x)
    $out[] = ['label' => $x->label, 'name' => $x->name, 'status' => $x->status, 'at' => ato_iso($x->acted_at), 'me' => (int) $x->id === (int) $me_id];
  $out[] = ['label' => 'ATO final copy', 'name' => 'ATO office', 'status' => 'later', 'me' => false];
  return $out;
}
function ato_route_reminders() {
  global $wpdb; $t = ato_sig_table(); $n = 0; $cut = gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS);
  foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status='sent' AND COALESCE(reminded_at, sent_at) <= %s", $cut)) as $r) {
    if (ato_sig_expired($r)) continue; if (ato_route_send($r, true) === 'sent') $n++;
  }
  return $n;
}

/* ------------------------------------------------------------------ roster upload (Employee Rosters Report export) */
// Only the columns below are read. Everything else in the export (home address, birth date, ethnicity,
// emergency contacts...) is ignored and never stored. The uploaded file is deleted after reading.

function ato_roster_columns() {
  return ['name' => 'Employee Full Name', 'empid' => 'Employee ID', 'code' => 'Job Code', 'fte' => 'FTE', 'start' => 'Job Effective Date', 'end' => 'Expected End Date',
          'grade' => 'Salary Plan Grade Code', 'step' => 'Job Step Number', 'annual' => 'Job Annual Rate', 'monthly' => 'Job Monthly Rate', 'email' => 'Employee Work Email',
          'cit' => 'Employee Citizenship Status Desc', 'dept' => 'Department'];
}
function ato_col_index($ref) { $l = preg_replace('/[^A-Z]/', '', strtoupper($ref)); $n = 0; for ($i = 0; $i < strlen($l); $i++) $n = $n * 26 + (ord($l[$i]) - 64); return $n - 1; }
function ato_read_xlsx($path, &$err) {
  if (!class_exists('ZipArchive')) { $err = 'This server cannot open .xlsx files (the PHP zip extension is missing). Save the export as CSV and upload that.'; return null; }
  $z = new ZipArchive(); if ($z->open($path) !== true) { $err = 'Could not open the workbook.'; return null; }
  $ss = []; $x = $z->getFromName('xl/sharedStrings.xml');
  if ($x !== false && ($sx = simplexml_load_string($x))) foreach ($sx->si as $si) { if (isset($si->t)) $ss[] = (string) $si->t; else { $t = ''; foreach ($si->r as $rr) $t .= (string) $rr->t; $ss[] = $t; } }
  $sheet = 'xl/worksheets/sheet1.xml'; $wb = $z->getFromName('xl/workbook.xml'); $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
  if ($wb && $rels && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m) && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*>/', $rels, $m2) && preg_match('/\bTarget="([^"]+)"/', $m2[0], $m3)) {
    $tg = ltrim($m3[1], '/'); $sheet = strpos($tg, 'xl/') === 0 ? $tg : 'xl/' . $tg;
  }
  $xml = $z->getFromName($sheet); $z->close();
  if ($xml === false || !($sx = simplexml_load_string($xml))) { $err = 'Could not read the first worksheet.'; return null; }
  $rows = [];
  foreach ($sx->sheetData->row as $row) {
    $cells = [];
    foreach ($row->c as $c) { $i = ato_col_index((string) $c['r']); $t = (string) $c['t'];
      $cells[$i] = $t === 's' ? ($ss[(int) $c->v] ?? '') : ($t === 'inlineStr' ? (string) $c->is->t : (string) $c->v); }
    if ($cells) { $r = array_fill(0, max(array_keys($cells)) + 1, ''); foreach ($cells as $i => $v) $r[$i] = $v; $rows[] = $r; }
  }
  return $rows;
}
function ato_read_csv($path, &$err) {
  $h = fopen($path, 'r'); if (!$h) { $err = 'Could not read the file.'; return null; }
  $rows = []; while (($r = fgetcsv($h)) !== false) $rows[] = $r; fclose($h);
  if ($rows && isset($rows[0][0])) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
  return $rows;
}
function ato_cell_date($v) {
  $v = trim((string) $v); if ($v === '') return '';
  if (is_numeric($v)) { $n = (float) $v; return ($n > 20000 && $n < 80000) ? gmdate('Y-m-d', (int) round(($n - 25569) * 86400)) : ''; }
  $t = strtotime($v); return $t ? gmdate('Y-m-d', $t) : '';
}
function ato_roster_build($rows, $src, &$stats, &$err) {
  $stats = ['rows' => 0, 'other' => 0, 'people' => 0, 'codes' => []];
  $want = ato_roster_columns(); $hi = -1;
  foreach (array_slice($rows, 0, 15) as $i => $r) if (in_array('employee id', array_map(function ($x) { return strtolower(trim((string) $x)); }, $r), true)) { $hi = $i; break; }
  if ($hi < 0) { $err = 'Could not find the header row (a column named “Employee ID”).'; return null; }
  $hdr = array_map(function ($x) { return strtolower(trim((string) $x)); }, $rows[$hi]); $col = [];
  foreach ($want as $k => $h) { $i = array_search(strtolower($h), $hdr, true); if ($i !== false) $col[$k] = $i; }
  foreach (['name', 'empid', 'code', 'start', 'end'] as $k) if (!isset($col[$k])) { $err = 'The file is missing the “' . $want[$k] . '” column.'; return null; }
  $PD = ['3252', '3253', '3254', '3255', '3256']; $GSR = ['3276', '3145', '3155', '3160']; $PRI = ['3253', '3254', '3255', '3256', '3252', '3276', '3145', '3155', '3160'];
  $ppl = [];
  foreach (array_slice($rows, $hi + 1) as $r) {
    $g = function ($k) use ($r, $col) { return isset($col[$k]) ? trim((string) ($r[$col[$k]] ?? '')) : ''; };
    $emp = preg_replace('/\D/', '', $g('empid')); if ($emp === '') continue; $stats['rows']++;
    $code = ltrim($g('code'), '0');
    if (!in_array($code, $PRI, true)) { $stats['other']++; continue; }
    $ln = ['code' => $code, 'fte' => round((float) $g('fte'), 4), 'start' => ato_cell_date($g('start')), 'end' => ato_cell_date($g('end')), 'annual' => (int) round((float) $g('annual')), 'monthly' => (int) round((float) $g('monthly')), '_grade' => $g('grade'), '_step' => $g('step')];
    if (!isset($ppl[$emp])) $ppl[$emp] = ['name' => $g('name'), 'email' => strtolower($g('email')), 'cit' => $g('cit'), 'dept' => $g('dept'), 'lines' => []];
    $prev = $ppl[$emp]['lines'][$code] ?? null;
    if (!$prev || strcmp($ln['start'], $prev['start']) >= 0) $ppl[$emp]['lines'][$code] = $ln;
  }
  $out = [];
  foreach ($ppl as $emp => $p) {
    $lines = array_values($p['lines']); usort($lines, function ($a, $b) use ($PRI) { return array_search($a['code'], $PRI, true) - array_search($b['code'], $PRI, true); });
    $main = $lines[0]; if ($main['code'] === '3160') { $stats['other']++; continue; }
    $codes = array_column($lines, 'code'); $pd = in_array($main['code'], $PD, true);
    $nm = $p['name']; if (strpos($nm, ',') !== false) { list($last, $first) = array_map('trim', explode(',', $nm, 2)); $nm = trim($first . ' ' . $last); }
    $ends = array_filter(array_column($lines, 'end')); $end = $ends ? max($ends) : '';
    $dept = trim(preg_replace('/^\s*\d+\s*-\s*/', '', $p['dept'])); $dept = $dept !== '' ? ucwords(strtolower($dept)) : '';
    $cd = strtolower($p['cit']); $cit = strpos($cd, 'citizen') !== false && strpos($cd, 'non') === false ? 'citizen' : (strpos($cd, 'perm') !== false ? 'pr' : (strpos($cd, 'resident') !== false ? 'ra' : 'work'));
    $row = ['id' => 'e' . $emp, 'name' => $nm, 'type' => $pd ? 'postdoc' : 'gsr', 'form' => $main['code'] === '3253' ? 'ATP4' : ($pd ? 'ATP5' : 'AT2'), 'title' => $main['code'], 'dept' => $dept,
            'ends' => $end ? gmdate('M Y', strtotime($end . ' 00:00:00 UTC')) : '', 'startIso' => $main['start'], 'endIso' => $end, 'fte' => $main['fte'], 'empid' => $emp, 'email' => $p['email'], 'cit' => $cit, 'src' => $src,
            'lines' => array_map(function ($l) { unset($l['_grade'], $l['_step']); return $l; }, $lines)];
    if ($pd && $main['code'] !== '3252' && in_array('3252', $codes, true)) { $row['supp'] = true; $row['suppCode'] = '3252'; }
    if (!$pd && in_array($main['code'], ['3145', '3155'], true) && in_array('3160', $codes, true)) { $row['supp'] = true; $row['suppCode'] = '3160'; }
    if ($pd) { foreach ($lines as $l) if (preg_match('/^L(\d+)/i', $l['_grade'], $m)) { $row['level'] = (int) $m[1]; break; } }
    else { if (preg_match('/^TF(\d+)/i', $main['_grade'], $m)) $row['point'] = (int) $m[1]; elseif ((int) $main['_step'] > 0) $row['point'] = (int) $main['_step']; }
    $out[] = $row; $stats['codes'][$main['code']] = ($stats['codes'][$main['code']] ?? 0) + 1;
  }
  $stats['people'] = count($out);
  if (!$out) { $err = 'No rows with a trainee title code (postdoc 3252–3256, GSR 3276 / 3145 / 3155) were found.'; return null; }
  return $out;
}
function ato_roster_save($people, $replace) {
  global $wpdb; $t = $wpdb->prefix . 'ato_roster'; $now = ato_now();
  if ($replace) $wpdb->query("DELETE FROM $t");
  foreach ($people as $r) $wpdb->replace($t, ['empid' => $r['empid'], 'name' => $r['name'], 'email' => $r['email'], 'title' => $r['title'], 'dept' => $r['dept'], 'end_date' => $r['endIso'] ? $r['endIso'] : null, 'src' => $r['src'], 'data' => wp_json_encode($r), 'uploaded_at' => $now]);
  update_option('ato_roster_meta', ['at' => $now, 'by' => wp_get_current_user()->display_name], false);
}

/* ------------------------------------------------------------------ reappointment reminders (daily job) */
// Cadence: 120, 90, 60 days, then weekly to 14 days, then every 3 days; ATO copied from 14 days.
// At the end date with no reappointment on file, a system termination notice is recorded.

function ato_today() { $s = ato_settings(); return ($s['pilot_mode'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $s['test_today'])) ? $s['test_today'] : current_time('Y-m-d'); }
function ato_reminder_band($days) { foreach ([0, 2, 5, 8, 11, 14, 18, 25, 32, 39, 46, 53, 60, 90, 120] as $t) if ($days <= $t) return $t; return null; }
function ato_days_between($a, $b) { return (int) round((strtotime($b . ' 00:00:00 UTC') - strtotime($a . ' 00:00:00 UTC')) / DAY_IN_SECONDS); }
function ato_initials($n) { $w = preg_split('/\s+/', trim((string) $n)); return implode('', array_map(function ($x) { return $x !== '' ? mb_strtoupper(mb_substr($x, 0, 1)) . '.' : ''; }, $w)); }
function ato_reminder_targets() {
  global $wpdb; $p = $wpdb->prefix; $out = []; $seen = [];
  foreach ($wpdb->get_results("SELECT * FROM {$p}ato_roster WHERE end_date IS NOT NULL") as $r) {
    $d = json_decode($r->data, true) ?: [];
    $out[] = ['key' => 'r:' . $r->empid, 'name' => $r->name, 'email' => strtolower($r->email), 'empid' => $r->empid, 'title' => $r->title, 'form' => $d['form'] ?? '', 'dept' => $r->dept, 'end' => $r->end_date, 'to' => [], 'pid' => '', 'src' => 'Roster'];
    if ($r->email) $seen[strtolower($r->email)] = 1;
  }
  $rows = $wpdb->get_results("SELECT p.*, u.status AS st FROM {$p}ato_packets p LEFT JOIN {$p}ato_packet_updates u ON u.packet_id=p.id WHERE p.source='intake' AND u.status IN ('filed','active')");
  foreach ($rows as $r) {
    $b = json_decode($r->bundle, true) ?: []; $pk = (array) ($b['packet'] ?? []);
    if (!in_array($pk['packet_type'] ?? '', ['new_appointment', 'reappointment'], true) || empty($pk['appointment_end_date'])) continue;
    $P = ato_people_map($b); $tr = (array) ($b['trainee'] ?? []); $tp = $P[$tr['person_id'] ?? ''] ?? [];
    $em = strtolower((string) ($tp['email'] ?? '')); if ($em && isset($seen[$em])) continue;
    $pi = $P[$pk['pi_id'] ?? ''] ?? [];
    $out[] = ['key' => 'p:' . $r->id, 'name' => (string) ($b['traineeName'] ?? ($tp['full_name'] ?? '')), 'email' => $em, 'empid' => '', 'title' => (string) ($pk['title_code'] ?? ''), 'form' => (string) ($pk['form_template'] ?? ''),
              'dept' => (string) ($tr['home_department'] ?? ''), 'end' => $pk['appointment_end_date'], 'to' => array_filter([$r->owner_email, $pi['email'] ?? '']), 'pid' => $r->id, 'src' => 'Portal packet'];
  }
  return $out;
}
// Reappointments already in the portal, by trainee email and name. A target stops once one starts within a month of its end date.
function ato_reappt_index() {
  global $wpdb; $p = $wpdb->prefix; $idx = [];
  $rows = $wpdb->get_results("SELECT p.bundle FROM {$p}ato_packets p LEFT JOIN {$p}ato_packet_updates u ON u.packet_id=p.id WHERE p.source='intake' AND (u.status IS NULL OR u.status NOT IN ('returned','closed','denied'))");
  foreach ($rows as $r) {
    $b = json_decode($r->bundle, true) ?: []; $pk = (array) ($b['packet'] ?? []); if (($pk['packet_type'] ?? '') !== 'reappointment') continue;
    $P = ato_people_map($b); $tp = $P[$b['trainee']['person_id'] ?? ''] ?? []; $st = (string) ($pk['appointment_start_date'] ?? '');
    foreach (array_filter([strtolower((string) ($tp['email'] ?? '')), 'n:' . strtolower(trim((string) ($b['traineeName'] ?? '')))]) as $k) if ($k !== 'n:') $idx[$k][] = $st;
  }
  return $idx;
}
function ato_reappt_stopped($t, $idx) {
  foreach ([$t['email'], 'n:' . strtolower(trim($t['name']))] as $k) {
    if (!$k || $k === 'n:' || empty($idx[$k])) continue;
    foreach ($idx[$k] as $st) if ($st === '' || ato_days_between($t['end'], $st) >= -31) return true;
  }
  return false;
}
function ato_reminder_recipients($t, $band) {
  $s = ato_settings(); $map = [];
  foreach (preg_split('/\r?\n/', (string) $s['reminder_to']) as $line) { if (strpos($line, ':') === false) continue; list($k, $v) = explode(':', $line, 2); $map[strtolower(trim($k))] = preg_split('/[\s,;]+/', trim($v)); }
  $to = (array) $t['to']; $d = strtolower($t['dept']);
  if (isset($map[$d])) $to = array_merge($to, $map[$d]); elseif (isset($map['*'])) $to = array_merge($to, $map['*']);
  if ($band <= 14 || !array_filter($to, 'is_email')) $to[] = $s['office_inbox'];
  return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $to)), 'is_email')));
}
function ato_reappt_reminders() {
  global $wpdb; $s = ato_settings(); $today = ato_today(); $idx = ato_reappt_index(); $tb = $wpdb->prefix . 'ato_reminders';
  $st = ['checked' => 0, 'sent' => 0, 'stopped' => 0, 'ended' => 0];
  foreach (ato_reminder_targets() as $t) {
    $days = ato_days_between($today, $t['end']); if ($days > 120 || $days < -7) continue; $st['checked']++;
    if (ato_reappt_stopped($t, $idx)) { $st['stopped']++; continue; }
    $band = ato_reminder_band(max(0, $days));
    if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $tb WHERE ref=%s AND end_date=%s AND band=%d", $t['key'], $t['end'], $band))) continue;
    $who = $s['reminder_names'] ? $t['name'] : ato_initials($t['name']) . ($t['empid'] ? ' (employee ID ending ' . substr($t['empid'], -4) . ')' : '');
    $what = trim($t['title'] . ($t['form'] ? ' · ' . $t['form'] : '') . ($t['dept'] ? ' · ' . $t['dept'] : ''));
    $endf = gmdate('M j, Y', strtotime($t['end'] . ' 00:00:00 UTC')); $to = ato_reminder_recipients($t, $band);
    if ($band === 0) {
      $subj = "Appointment ended without a reappointment: $who";
      $lines = ["$who ($what). The appointment ended $endf and no reappointment is on file in the portal.", 'The portal recorded a termination notice for the ATO office. If a reappointment is under way outside the portal, tell the ATO office.'];
    } else {
      $subj = "Reappointment due: $who ends in $days day" . ($days === 1 ? '' : 's');
      $lines = ["$who ($what). The current appointment ends $endf, $days day" . ($days === 1 ? '' : 's') . ' from today.', 'Start the reappointment in the intake: Existing appointment → Reappointment. These reminders stop once it is submitted.'];
    }
    $body = "Hello,\n\n" . implode("\n\n", $lines) . "\n\nOpen the intake: " . ato_url('intake');
    $r = ato_mail($to, $subj, $body, $t['pid'], 'reappt_' . $band, (bool) $t['pid']);
    if ($band === 0) { ato_make_termination($t); $st['ended']++; }
    if ($r === 'off' && $band !== 0) continue;
    $wpdb->insert($tb, ['ref' => $t['key'], 'end_date' => $t['end'], 'band' => $band, 'sent_at' => ato_now(), 'recipients' => implode(', ', $to), 'result' => $r]);
    if ($r === 'sent') $st['sent']++;
  }
  return $st;
}
function ato_make_termination($t) {
  global $wpdb; $p = $wpdb->prefix;
  $id = 'TN-' . ($t['empid'] ? $t['empid'] : substr(md5($t['key']), 0, 10)) . '-' . str_replace('-', '', $t['end']);
  if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}ato_packets WHERE id=%s", $id))) return;
  $d = ato_today(); $now = ato_now();
  $bundle = ['packet' => ['id' => $id, 'packet_type' => 'termination_notice', 'trainee_id' => $id . '-TR', 'title_code' => $t['title'] ?: null, 'title_codes' => null, 'is_dual_title' => false,
      'form_template' => null, 'status' => 'filed', 'origin' => 'system', 'action_type' => null, 'appointment_start_date' => null, 'appointment_end_date' => $t['end'], 'fte_percent' => null,
      'pi_id' => null, 'faculty_mentor_id' => null, 'department_admin_id' => null, 'ato_analyst_id' => null, 'has_supplement' => false, 'created_at' => $d, 'submitted_at' => $d, 'filed_at' => $d, 'source' => 'system'],
    'trainee' => ['id' => $id . '-TR', 'person_id' => $id . '-T', 'trainee_type' => in_array($t['title'], ['3252', '3253', '3254', '3255', '3256'], true) ? 'postdoc' : 'gsr', 'home_department' => $t['dept']],
    'people' => [['id' => $id . '-T', 'full_name' => $t['name'], 'email' => $t['email'], 'roles' => ['trainee']]],
    'funding_lines' => [], 'signature_steps' => [], 'validation_results' => [], 'attachments' => [], 'traineeName' => $t['name']];
  $wpdb->insert($p . 'ato_packets', ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'source' => 'system', 'owner_id' => 0, 'owner_email' => '', 'owner_name' => 'ATO Portal', 'bundle' => wp_json_encode($bundle)]);
  ato_set_status($id, 'filed', 'ATO Portal');
  ato_insert_event($id, 'note', 'Termination notice generated at natural expiry (' . $t['end'] . '). Source: ' . $t['src'] . '.', '#8195a3');
}
function ato_run_daily() {
  $s = ato_settings(); $a = ato_route_reminders(); $b = $s['reappt_on'] ? ato_reappt_reminders() : ['checked' => 0, 'sent' => 0, 'stopped' => 0, 'ended' => 0];
  $res = ['at' => ato_now(), 'today' => ato_today(), 'approvals' => $a, 'reappt' => $b]; update_option('ato_last_daily', $res, false); return $res;
}

function ato_wipe() { global $wpdb; foreach (['ato_packet_events', 'ato_packet_updates', 'ato_packets', 'ato_signatures', 'ato_reminders'] as $t) $wpdb->query("DELETE FROM {$wpdb->prefix}$t"); }
function ato_save_ref($ref) {
  $u = wp_get_current_user(); $c = ato_content();
  $log = (array) get_option('ato_portal_content_log', []);
  array_unshift($log, ['at' => ato_now(), 'by' => $u->display_name, 'ref' => $c['ref']]);
  update_option('ato_portal_content_log', array_slice($log, 0, 10), false);
  update_option('ato_portal_content', ['ref' => $ref, 'updated_at' => ato_now(), 'updated_by' => $u->display_name], false);
}

/* ------------------------------------------------------------------ wp-admin: ATO Portal */

add_action('admin_menu', function () {
  add_menu_page('ATO Portal', 'ATO Portal', 'ato_manage', 'ato-portal', 'ato_admin_page', 'dashicons-clipboard', 58);
});

add_action('admin_post_ato_save', function () {
  if (!current_user_can('ato_manage')) wp_die('Not allowed.');
  check_admin_referer('ato_save');
  $tab = sanitize_key($_POST['tab'] ?? 'access'); $msg = 'Saved.'; $type = 'success';
  if ($tab === 'access') {
    $s = ato_settings();
    foreach (['password_login', 'signup', 'sso'] as $k) $s[$k] = empty($_POST[$k]) ? 0 : 1;
    $s['signup_domains'] = sanitize_text_field(wp_unslash($_POST['signup_domains'] ?? ''));
    $s['sso_label'] = sanitize_text_field(wp_unslash($_POST['sso_label'] ?? '')) ?: 'Sign in with UCLA Logon';
    $s['sso_url'] = esc_url_raw(wp_unslash($_POST['sso_url'] ?? ''));
    if (!$s['password_login'] && !$s['sso']) { $msg = 'Turn on at least one way to sign in. Nothing was changed.'; $type = 'error'; }
    else update_option('ato_portal_settings', $s, false);
  } elseif ($tab === 'staff') {
    $act = sanitize_key($_POST['act'] ?? '');
    $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    $u = $email ? get_user_by('email', $email) : null;
    if ($act === 'add') {
      if (!is_email($email)) { $msg = 'Enter a valid email address.'; $type = 'error'; }
      elseif ($u) { $u->add_role('ato_staff'); if (!empty($_POST['manage'])) $u->add_cap('ato_manage'); $msg = "$email now has console access."; }
      else {
        $pend = (array) get_option('ato_pending_staff', []);
        $pend[$email] = ['name' => sanitize_text_field(wp_unslash($_POST['full_name'] ?? '')), 'manage' => !empty($_POST['manage'])];
        update_option('ato_pending_staff', $pend, false);
        $msg = "$email has no site account yet. They get console access the first time they sign in.";
      }
    } elseif ($act === 'remove' && $u) {
      if (in_array('administrator', (array) $u->roles, true)) { $msg = 'Site administrators always have access. Change their WordPress role instead.'; $type = 'error'; }
      else { $u->remove_role('ato_staff'); $u->remove_cap('ato_manage'); $u->remove_cap('ato_work'); if (!$u->roles) $u->set_role('subscriber'); $msg = "Removed console access for $email."; }
    } elseif ($act === 'toggle_manage' && $u) {
      if ($u->has_cap('ato_manage') && !in_array('administrator', (array) $u->roles, true)) { $u->remove_cap('ato_manage'); $msg = "$email can no longer edit portal settings."; }
      else { $u->add_cap('ato_manage'); $msg = "$email can now edit portal settings."; }
    } elseif ($act === 'cancel') {
      $pend = (array) get_option('ato_pending_staff', []); unset($pend[$email]); update_option('ato_pending_staff', $pend, false); $msg = 'Invitation removed.';
    }
  } elseif ($tab === 'content') {
    if (!empty($_POST['restore'])) { $u = wp_get_current_user(); ato_save_ref(null); update_option('ato_portal_content', ['ref' => null, 'updated_at' => ato_now(), 'updated_by' => $u->display_name], false); $msg = 'Restored the built-in reference tables.'; }
    else {
      $in = json_decode(wp_unslash($_POST['ref_json'] ?? ''), true); $err = '';
      $ref = is_array($in) ? ato_validate_ref($in, $err) : null;
      if (!$ref) { $msg = 'Not saved: ' . ($err ?: 'the table data could not be read.'); $type = 'error'; }
      else { ato_save_ref($ref); $msg = 'Reference tables saved. The intake uses them from the next page load.'; }
    }
  } elseif ($tab === 'email') {
    $s = ato_settings();
    if (!empty($_POST['test'])) {
      $to = sanitize_email(wp_unslash($_POST['test_to'] ?? ''));
      $r = is_email($to) ? ato_mail($to, 'ATO Portal test email', "This is a test from wp-admin → ATO Portal → Email.\nIf you received it, the site can send portal email.", '', 'test', false) : 'failed';
      $msg = ['sent' => "Test email handed to the mail server for $to. Check the inbox (and spam).", 'off' => 'Turn email on and save before sending a test.', 'blocked' => "$to is outside the allowed domains.", 'failed' => 'The site could not send the email. Check the SMTP setup.'][$r];
      $type = $r === 'sent' ? 'success' : 'error';
    } else {
      foreach (['email_on', 'n_new', 'n_receipt', 'n_returned', 'n_status', 'n_assign', 'n_console'] as $k) $s[$k] = empty($_POST[$k]) ? 0 : 1;
      foreach (['office_inbox', 'from_address', 'email_redirect'] as $k) $s[$k] = sanitize_email(wp_unslash($_POST[$k] ?? ''));
      $s['from_name'] = sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')) ?: 'ATO Portal';
      $s['email_domains'] = sanitize_text_field(wp_unslash($_POST['email_domains'] ?? ''));
      foreach (['intake_url', 'console_url', 'approve_url'] as $k) $s[$k] = esc_url_raw(wp_unslash($_POST[$k] ?? ''));
      update_option('ato_portal_settings', $s, false);
      if ($s['email_on'] && $s['n_new'] && !$s['office_inbox']) { $msg = 'Saved. Add an office inbox, or new-submission emails have nowhere to go.'; $type = 'warning'; }
    }
  } elseif ($tab === 'roster') {
    $act = sanitize_key($_POST['act'] ?? ''); $s = ato_settings();
    if ($act === 'upload') {
      $f = $_FILES['roster'] ?? null; $err = ''; $people = null; $stats = [];
      if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $err = 'Choose a file to upload.';
      elseif ($f['size'] > 10 * 1024 * 1024) $err = 'That file is over 10 MB.';
      else {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' ? ato_read_xlsx($f['tmp_name'], $err) : ($ext === 'csv' ? ato_read_csv($f['tmp_name'], $err) : null);
        if (!in_array($ext, ['xlsx', 'csv'], true)) $err = 'Upload the export as .xlsx or .csv.';
        $src = preg_match('/\(([^)]+)\)/', $f['name'], $m) ? sanitize_text_field($m[1]) : sanitize_text_field(preg_replace('/\.[^.]+$/', '', $f['name']));
        if ($rows) $people = ato_roster_build($rows, $src, $stats, $err);
      }
      if ($f && !empty($f['tmp_name']) && file_exists($f['tmp_name'])) @unlink($f['tmp_name']);
      if (!$people) { $msg = 'Not loaded: ' . ($err ?: 'the file could not be read.'); $type = 'error'; }
      else {
        $replace = ($_POST['mode'] ?? '') === 'replace'; ato_roster_save($people, $replace);
        $codes = []; foreach ($stats['codes'] as $k => $v) $codes[] = "$k ×$v";
        $log = (array) get_option('ato_roster_log', []);
        array_unshift($log, ['at' => ato_now(), 'by' => wp_get_current_user()->display_name, 'file' => sanitize_file_name($f['name']), 'people' => $stats['people'], 'rows' => $stats['rows'], 'mode' => $replace ? 'replace' : 'merge']);
        update_option('ato_roster_log', array_slice($log, 0, 20), false);
        $msg = "Read {$stats['rows']} rows: {$stats['people']} people " . ($replace ? 'loaded (roster replaced)' : 'added or updated') . ' — ' . implode(', ', $codes) . '.' . ($stats['other'] ? " {$stats['other']} rows skipped (not a trainee title code)." : '') . ' The file was not kept.';
      }
    } elseif ($act === 'clear') {
      if (($_POST['confirm'] ?? '') !== 'CLEAR') { $msg = 'Type CLEAR to confirm.'; $type = 'error'; }
      else { global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}ato_roster"); delete_option('ato_roster_meta'); $msg = 'Roster cleared. The intake falls back to its built-in sample roster while sample data is on.'; }
    } elseif ($act === 'settings') {
      $s['reappt_on'] = empty($_POST['reappt_on']) ? 0 : 1; $s['reminder_names'] = empty($_POST['reminder_names']) ? 0 : 1;
      $s['reminder_to'] = sanitize_textarea_field(wp_unslash($_POST['reminder_to'] ?? ''));
      $tt = sanitize_text_field(wp_unslash($_POST['test_today'] ?? '')); $s['test_today'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tt) ? $tt : '';
      update_option('ato_portal_settings', $s, false); $msg = 'Reminder settings saved.';
    } elseif ($act === 'run') {
      $r = ato_run_daily(); $b = $r['reappt'];
      $msg = "Daily job ran for {$r['today']}: {$r['approvals']} approval reminder(s); {$b['checked']} appointments in the 120-day window, {$b['sent']} reminder(s) sent, {$b['stopped']} stopped by a submitted reappointment, {$b['ended']} termination notice(s) recorded.";
    }
  } elseif ($tab === 'data') {
    $s = ato_settings();
    if (isset($_POST['pilot_toggle'])) { $s['pilot_mode'] = empty($_POST['pilot_mode']) ? 0 : 1; $s['sample_packets'] = empty($_POST['sample_packets']) ? 0 : 1; update_option('ato_portal_settings', $s, false); }
    if (!empty($_POST['wipe'])) {
      if (!$s['pilot_mode']) { $msg = 'Turn on pilot mode before deleting data.'; $type = 'error'; }
      elseif (($_POST['confirm'] ?? '') !== 'DELETE') { $msg = 'Type DELETE to confirm.'; $type = 'error'; }
      else { ato_wipe(); $msg = 'All packets, office actions and audit entries were deleted.'; }
    }
  }
  set_transient('ato_admin_msg_' . get_current_user_id(), [$msg, $type], 60);
  wp_safe_redirect(admin_url('admin.php?page=ato-portal&tab=' . $tab)); exit;
});

function ato_admin_page() {
  global $wpdb;
  $tab = sanitize_key($_GET['tab'] ?? 'access'); $s = ato_settings();
  $tabs = ['access' => 'Sign-in', 'staff' => 'ATO staff', 'email' => 'Email', 'roster' => 'Roster & reminders', 'content' => 'Reference tables', 'data' => 'Data'];
  $flash = get_transient('ato_admin_msg_' . get_current_user_id()); delete_transient('ato_admin_msg_' . get_current_user_id());
  $form = function ($t) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ato_save"><input type="hidden" name="tab" value="' . esc_attr($t) . '">'; wp_nonce_field('ato_save'); };
  echo '<div class="wrap"><h1>ATO Portal</h1>';
  echo '<p>Back end for the <a href="' . esc_url(home_url('/tools/ato/')) . '" target="_blank">ATO Intake and Console</a>. Changes here take effect without a code deploy.</p>';
  if ($flash) echo '<div class="notice notice-' . esc_attr($flash[1]) . ' is-dismissible"><p>' . esc_html($flash[0]) . '</p></div>';
  echo '<nav class="nav-tab-wrapper">';
  foreach ($tabs as $k => $l) echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=ato-portal&tab=' . $k)) . '">' . esc_html($l) . '</a>';
  echo '</nav><div style="max-width:960px;margin-top:20px">';

  if ($tab === 'access') {
    $form('access');
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th>Single sign-on</th><td><label><input type="checkbox" name="sso" value="1"' . checked($s['sso'], 1, false) . '> Show a single sign-on button</label>';
    echo '<p class="description">Turn this on once the site&rsquo;s SSO plugin (UCLA Logon / Shibboleth / SAML) is set up. The button opens the site&rsquo;s login page in the full browser window, since SSO pages can&rsquo;t load inside an embedded frame, and returns to the tool afterwards.</p></td></tr>';
    echo '<tr><th><label for="sso_label">Button label</label></th><td><input class="regular-text" id="sso_label" name="sso_label" value="' . esc_attr($s['sso_label']) . '"></td></tr>';
    echo '<tr><th><label for="sso_url">SSO login URL</label></th><td><input class="regular-text code" id="sso_url" name="sso_url" value="' . esc_attr($s['sso_url']) . '" placeholder="' . esc_attr(wp_login_url()) . '"><p class="description">Leave blank to use the site&rsquo;s normal login page. Only fill this in if the SSO plugin uses a different address.</p></td></tr>';
    echo '<tr><th>Password sign-in</th><td><label><input type="checkbox" name="password_login" value="1"' . checked($s['password_login'], 1, false) . '> Allow email + password sign-in with a WordPress account</label><p class="description">Useful for testing before SSO is ready. Turn it off once everyone signs in with SSO.</p></td></tr>';
    echo '<tr><th>Self sign-up</th><td><label><input type="checkbox" name="signup" value="1"' . checked($s['signup'], 1, false) . '> Let people create their own intake account</label><p class="description">New accounts can use the intake only. Console access is always granted on the ATO staff tab.</p></td></tr>';
    echo '<tr><th><label for="signup_domains">Allowed email domains</label></th><td><input class="regular-text" id="signup_domains" name="signup_domains" value="' . esc_attr($s['signup_domains']) . '"><p class="description">Comma-separated. Applies to self sign-up.</p></td></tr>';
    echo '</table>'; submit_button('Save sign-in settings'); echo '</form>';
  }

  if ($tab === 'staff') {
    $users = get_users(['capability' => 'ato_work', 'orderby' => 'display_name']);
    echo '<h2>People with console access</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Can edit portal settings</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
      $admin = in_array('administrator', (array) $u->roles, true);
      echo '<tr><td>' . esc_html($u->display_name) . ($admin ? ' <span class="description">(site admin)</span>' : '') . '</td><td>' . esc_html($u->user_email) . '</td><td>' . ($u->has_cap('ato_manage') ? 'Yes' : 'No') . '</td><td style="text-align:right;white-space:nowrap">';
      if (!$admin) {
        $form('staff'); echo '<input type="hidden" name="email" value="' . esc_attr($u->user_email) . '"><button class="button" name="act" value="toggle_manage">' . ($u->has_cap('ato_manage') ? 'Revoke settings' : 'Allow settings') . '</button> <button class="button button-link-delete" name="act" value="remove" onclick="return confirm(\'Remove console access?\')">Remove</button></form>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    $pend = (array) get_option('ato_pending_staff', []);
    if ($pend) {
      echo '<h2 style="margin-top:28px">Waiting for first sign-in</h2><table class="widefat striped"><tbody>';
      foreach ($pend as $e => $p) { echo '<tr><td>' . esc_html($p['name'] ?: '—') . '</td><td>' . esc_html($e) . '</td><td style="text-align:right">'; $form('staff'); echo '<input type="hidden" name="email" value="' . esc_attr($e) . '"><button class="button" name="act" value="cancel">Cancel</button></form></td></tr>'; }
      echo '</tbody></table>';
    }
    echo '<h2 style="margin-top:28px">Add a staff member</h2>'; $form('staff');
    echo '<input type="hidden" name="act" value="add"><table class="form-table" role="presentation">';
    echo '<tr><th><label for="ae">Email</label></th><td><input type="email" class="regular-text" id="ae" name="email" required placeholder="name@mednet.ucla.edu"><p class="description">The email on their site account, or the one SSO will report for them.</p></td></tr>';
    echo '<tr><th><label for="an">Name</label></th><td><input class="regular-text" id="an" name="full_name"></td></tr>';
    echo '<tr><th>Settings</th><td><label><input type="checkbox" name="manage" value="1"> Can also edit portal settings and reference tables</label></td></tr>';
    echo '</table>'; submit_button('Grant console access'); echo '</form>';
  }

  if ($tab === 'content') {
    $c = ato_content(); $ref = ato_ref_effective();
    echo '<p>These tables drive the stipend and salary figures in the intake. NRSA amounts are fixed exact rates. UCLA scales are minimums, so a mentor may pay more. Add a new effective-dated row when a scale changes; the intake picks the row in effect on each appointment date.</p>';
    echo '<p class="description">' . (is_array($c['ref']) ? 'Last saved ' . esc_html(get_date_from_gmt($c['updated_at'], 'M j, Y g:i a')) . ' by ' . esc_html($c['updated_by']) . '.' : 'Using the built-in values that ship with the tool.') . '</p>';
    $form('content');
    echo '<textarea name="ref_json" id="ato-ref-json" hidden>' . esc_textarea(wp_json_encode($ref)) . '</textarea><div id="ato-ref-editor"></div>';
    echo '<p class="submit"><button class="button button-primary">Save reference tables</button> <button class="button" name="restore" value="1" onclick="return confirm(\'Replace the saved tables with the built-in values?\')">Restore built-in values</button></p></form>';
    $log = (array) get_option('ato_portal_content_log', []);
    if ($log) { echo '<h2>Change history</h2><ul>'; foreach ($log as $l) echo '<li>' . esc_html(get_date_from_gmt($l['at'], 'M j, Y g:i a')) . ' &middot; ' . esc_html($l['by']) . '</li>'; echo '</ul>'; }
    ato_ref_editor_js();
  }

  if ($tab === 'email') {
    $cb = function ($k, $label, $desc = '') use ($s) { echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="' . $k . '" value="1"' . checked($s[$k], 1, false) . '> ' . esc_html($label) . '</label>' . ($desc ? '<p class="description" style="margin:-2px 0 10px 24px">' . esc_html($desc) . '</p>' : ''); };
    $tx = function ($k, $label, $desc = '', $ph = '') use ($s) { echo '<tr><th><label for="' . $k . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="' . $k . '" name="' . $k . '" value="' . esc_attr($s[$k]) . '" placeholder="' . esc_attr($ph) . '">' . ($desc ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>'; };
    echo '<p>The portal sends plain-text email through the site&rsquo;s mail setup (<code>wp_mail</code>). Messages carry the packet ID, type, form and title code, plus a link. They never include the trainee&rsquo;s name, salary, funding lines or visa details. Emails that ATO staff write in the console are sent as written.</p>';
    $form('email'); echo '<table class="form-table" role="presentation">';
    echo '<tr><th>Email</th><td>'; $cb('email_on', 'Send portal email', 'While off, nothing is sent. The console still logs what it would have sent.'); echo '</td></tr>';
    $tx('office_inbox', 'Office inbox', 'Gets new-submission emails. Also the Reply-To on every message.', 'ato@mednet.ucla.edu');
    $tx('from_name', 'From name');
    $tx('from_address', 'From address', 'Leave blank to use the site default. It must be an address the site\'s mail service is allowed to send as, or messages land in spam.');
    $tx('email_redirect', 'Pilot redirect', 'While testing, send every message here instead, with [TEST] in the subject and the real recipients listed at the top. The domain filter is skipped while this is set, since nothing reaches the real recipients. Clear it at go-live.', 'you@mednet.ucla.edu');
    $tx('email_domains', 'Allowed recipient domains', 'Comma-separated. Subdomains are included. Anything else is not sent and is flagged in the audit trail. Leave blank to allow any domain.');
    $tx('intake_url', 'Intake page link', 'The page people should open, such as the Elementor page with the intake iframe.', ato_url('intake'));
    $tx('console_url', 'Console page link', '', ato_url('console'));
    $tx('approve_url', 'Approval page link', 'Where approval emails send fund managers, PIs and mentors. Leave blank for the tool page itself. They do not need a site account, so do not point this at a page that requires sign-in.', home_url('/tools/ato/approve.html'));
    echo '<tr><th>Send when</th><td>';
    $cb('n_new', 'A packet is submitted or resubmitted, or a visa inquiry is opened', 'To the office inbox.');
    $cb('n_receipt', 'The submitter gets a receipt', 'To the department administrator who submitted.');
    $cb('n_returned', 'The office returns a packet for correction', 'To the submitter, with the return note.');
    $cb('n_status', 'The office moves a packet forward', 'To the submitter, for: ' . implode(', ', array_map('ato_status_label', ato_notify_statuses())) . '.');
    $cb('n_assign', 'A packet is assigned to an analyst', 'To the analyst, unless they assigned it to themselves.');
    $cb('n_console', 'ATO staff send an email or reminder from the console', 'Sent as written, to the address in the console. Bulk reminders are logged only.');
    echo '<p class="description" style="margin:8px 0 0">Approval requests to fund managers, PIs and mentors, and reappointment reminders, are always sent while portal email is on. Reminder settings are on the Roster &amp; reminders tab.</p>';
    echo '</td></tr></table>'; submit_button('Save email settings'); echo '</form>';
    echo '<h2>Send a test</h2>'; $form('email');
    echo '<input type="hidden" name="test" value="1"><p><input type="email" class="regular-text" name="test_to" value="' . esc_attr(wp_get_current_user()->user_email) . '"> <button class="button">Send test email</button></p><p class="description">The pilot redirect applies to tests too.</p></form>';
    $log = (array) get_option('ato_mail_log', []);
    echo '<h2>Recent email</h2>';
    if (!$log) echo '<p class="description">Nothing sent yet.</p>';
    else {
      $rl = ['sent' => 'Sent', 'off' => 'Email off', 'blocked' => 'Blocked domain', 'failed' => 'Failed'];
      echo '<table class="widefat striped"><thead><tr><th>When</th><th>To</th><th>Subject</th><th>Trigger</th><th>Result</th></tr></thead><tbody>';
      foreach ($log as $l) echo '<tr><td style="white-space:nowrap">' . esc_html(get_date_from_gmt($l['at'], 'M j, g:i a')) . '</td><td>' . esc_html($l['to']) . '</td><td>' . esc_html($l['subject']) . '</td><td>' . esc_html($l['trigger']) . '</td><td>' . esc_html($rl[$l['result']] ?? $l['result']) . '</td></tr>';
      echo '</tbody></table><p class="description">Last 50 attempts. &ldquo;Sent&rdquo; means the site handed the message to its mail service, not that it was delivered.</p>';
    }
  }

  if ($tab === 'roster') {
    $p = $wpdb->prefix; $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ato_roster"); $meta = (array) get_option('ato_roster_meta', []);
    echo '<p>Upload the <b>Employee Rosters Report</b> export (.xlsx or .csv). The intake&rsquo;s Existing appointment lookup and the reappointment reminders both use it. Upload the postdoc and GSR exports one after the other with <i>Add or update</i>, or use <i>Replace</i> to start fresh.</p>';
    echo '<p class="description">Only these columns are read: ' . esc_html(implode(', ', ato_roster_columns())) . '. Everything else in the export, including home address, birth date, ethnicity and emergency contacts, is ignored and never stored. The uploaded file is deleted as soon as it has been read.</p>';
    echo '<p><b>' . $n . ' people</b> on the roster' . (!empty($meta['at']) ? ', last upload ' . esc_html(get_date_from_gmt($meta['at'], 'M j, Y g:i a')) . ' by ' . esc_html($meta['by']) : '') . '.';
    if ($n) { $bits = []; foreach ($wpdb->get_results("SELECT title, COUNT(*) n FROM {$p}ato_roster GROUP BY title ORDER BY n DESC") as $r) $bits[] = esc_html($r->title) . ' &times;' . (int) $r->n; echo ' ' . implode(' &middot; ', $bits); }
    echo '</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #dcdcde;padding:14px 16px;margin:0 0 16px"><input type="hidden" name="action" value="ato_save"><input type="hidden" name="tab" value="roster"><input type="hidden" name="act" value="upload">'; wp_nonce_field('ato_save');
    echo '<p style="margin-top:0"><input type="file" name="roster" accept=".xlsx,.csv" required></p><p><label><input type="radio" name="mode" value="merge" checked> Add or update these people</label> &nbsp; <label><input type="radio" name="mode" value="replace"> Replace the whole roster</label></p>';
    submit_button('Upload roster', 'primary', 'submit', false); echo '</form>';
    $log = (array) get_option('ato_roster_log', []);
    if ($log) { echo '<details style="margin-bottom:16px"><summary>Upload history</summary><ul>'; foreach ($log as $l) echo '<li>' . esc_html(get_date_from_gmt($l['at'], 'M j, Y g:i a') . ' · ' . $l['by'] . ' · ' . $l['file'] . ' · ' . $l['people'] . ' people · ' . $l['mode']) . '</li>'; echo '</ul></details>'; }
    if ($n) { $form('roster'); echo '<input type="hidden" name="act" value="clear"><p><input name="confirm" placeholder="Type CLEAR"> <button class="button button-link-delete">Clear the roster</button></p></form>'; }

    echo '<h2 style="margin-top:28px">Reappointment reminders</h2>';
    echo '<p>A daily job emails reminders for each appointment ending within 120 days: at 120, 90 and 60 days, then weekly to 14 days, then every 3 days. The ATO office inbox is copied from 14 days. Reminders stop once a reappointment for that person is submitted in the portal. At the end date with nothing on file, the portal records a termination notice in the console.</p>';
    $form('roster'); echo '<input type="hidden" name="act" value="settings"><table class="form-table" role="presentation">';
    echo '<tr><th>Reminders</th><td><label><input type="checkbox" name="reappt_on" value="1"' . checked($s['reappt_on'], 1, false) . '> Send reappointment reminders</label><br><label><input type="checkbox" name="reminder_names" value="1"' . checked($s['reminder_names'], 1, false) . '> Include the trainee&rsquo;s full name in the email</label><p class="description">Off: the email uses initials and the last 4 digits of the employee ID.</p></td></tr>';
    echo '<tr><th><label for="rt">Who gets them</label></th><td><textarea id="rt" name="reminder_to" rows="4" class="large-text code" placeholder="Semel Institute: admin1@mednet.ucla.edu, admin2@mednet.ucla.edu&#10;*: ato-office@mednet.ucla.edu">' . esc_textarea($s['reminder_to']) . '</textarea><p class="description">One line per department, as it appears on the roster: <code>Department: email, email</code>. <code>*</code> covers every other department. Packets submitted through the portal also remind the submitter and the PI. With no match, reminders go to the office inbox.</p></td></tr>';
    if ($s['pilot_mode']) echo '<tr><th><label for="tt">Pretend today is</label></th><td><input type="date" id="tt" name="test_today" value="' . esc_attr($s['test_today']) . '"><p class="description">Pilot mode only. Lets you test the cadence without waiting. Clear it when done.</p></td></tr>';
    echo '</table>'; submit_button('Save reminder settings'); echo '</form>';
    $last = get_option('ato_last_daily');
    $form('roster'); echo '<input type="hidden" name="act" value="run"><p><button class="button">Run the daily job now</button> <span class="description">' . ($last ? 'Last run ' . esc_html(get_date_from_gmt($last['at'], 'M j, g:i a')) . ' for ' . esc_html($last['today']) . '.' : 'Not run yet.') . ' Next scheduled: ' . (($nx = wp_next_scheduled('ato_daily')) ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $nx), 'M j, g:i a')) : 'not scheduled') . '.</span></p></form>';

    $today = ato_today(); $idx = ato_reappt_index(); $up = [];
    foreach (ato_reminder_targets() as $t) { $d = ato_days_between($today, $t['end']); if ($d <= 120 && $d >= -7) { $t['days'] = $d; $up[] = $t; } }
    usort($up, function ($a, $b) { return $a['days'] - $b['days']; });
    echo '<h2>Ending within 120 days of ' . esc_html(gmdate('M j, Y', strtotime($today . ' 00:00:00 UTC'))) . ' (' . count($up) . ')</h2>';
    if (!$up) echo '<p class="description">None. Upload a roster, or file packets through the portal.</p>';
    else {
      echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Title</th><th>Ends</th><th>Days</th><th>Source</th><th>Last reminder</th><th>Status</th></tr></thead><tbody>';
      foreach (array_slice($up, 0, 200) as $t) {
        $lr = $wpdb->get_row($wpdb->prepare("SELECT band, sent_at, result FROM {$p}ato_reminders WHERE ref=%s AND end_date=%s ORDER BY band ASC LIMIT 1", $t['key'], $t['end']));
        $stop = ato_reappt_stopped($t, $idx);
        echo '<tr><td>' . esc_html($t['name']) . '</td><td>' . esc_html($t['title']) . '</td><td>' . esc_html($t['end']) . '</td><td>' . (int) $t['days'] . '</td><td>' . esc_html($t['src']) . '</td><td>' . ($lr ? esc_html($lr->band . '-day · ' . get_date_from_gmt($lr->sent_at, 'M j') . ' · ' . $lr->result) : '&mdash;') . '</td><td>' . ($stop ? 'Stopped: reappointment submitted' : ($t['days'] < 0 ? 'Ended' : 'Next: ' . ato_reminder_band(max(0, $t['days'])) . '-day')) . '</td></tr>';
      }
      echo '</tbody></table>';
    }
  }

  if ($tab === 'data') {
    $p = $wpdb->prefix; $np = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ato_packets"); $ne = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ato_packet_events");
    echo '<p>' . esc_html("$np packets and $ne audit entries are stored in this site’s database.") . '</p>';
    $form('data'); echo '<input type="hidden" name="pilot_toggle" value="1"><label><input type="checkbox" name="pilot_mode" value="1"' . checked($s['pilot_mode'], 1, false) . '> Pilot mode</label><p class="description">While on, ATO staff can wipe all test data from the console&rsquo;s Settings. Turn it off before real packets are entered.</p>';
    echo '<p><label><input type="checkbox" name="sample_packets" value="1"' . checked($s['sample_packets'], 1, false) . '> Show the built-in sample packets in the console</label></p><p class="description">About 160 fabricated packets for training. They never touch the database. They are hidden automatically when pilot mode is off.</p>'; submit_button('Save'); echo '</form>';
    if ($s['pilot_mode']) {
      echo '<h2>Delete all test data</h2>'; $form('data');
      echo '<input type="hidden" name="wipe" value="1"><p>Deletes every packet, office action and audit entry. This cannot be undone.</p><p><input name="confirm" placeholder="Type DELETE"> <button class="button button-link-delete">Delete everything</button></p></form>';
    }
  }
  echo '</div></div>';
}

function ato_ref_editor_js() { ?>
<style>
#ato-ref-editor fieldset{background:#fff;border:1px solid #dcdcde;padding:14px 16px 16px;margin:0 0 16px}
#ato-ref-editor legend{font-weight:600;padding:0 6px}
#ato-ref-editor .ato-row{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-top:8px}
#ato-ref-editor .ato-cell{display:flex;flex-direction:column;font-size:11px;color:#50575e}
#ato-ref-editor .ato-cell input{width:96px}
#ato-ref-editor .ato-cell input[type=date]{width:150px}
#ato-ref-editor .ato-text{width:100%;max-width:480px}
</style>
<script>
(function(){
  var ta=document.getElementById('ato-ref-json'), root=document.getElementById('ato-ref-editor'), data=JSON.parse(ta.value);
  var META={
    nrsaEffective:['NRSA rates effective'], nrsaSource:['NRSA source notice'],
    nrsaPostdoc:['NRSA postdoc stipend, annual, by full years of experience',function(i){return i===7?'7+ yrs':i+' yrs';}],
    nrsaPredoc:['NRSA predoc stipend, annual'],
    postdocScale:['UCLA postdoc salaried minimum, annual, by level',function(i){return 'Level '+i;}], postdocSource:['Postdoc scale source'],
    gsrScale:['GSR salary scale, 50% monthly rate (100% annual = rate × 24)',function(i){return 'Point '+(i+1);}], gsrSource:['GSR scale source']
  };
  function el(t,a,kids){var e=document.createElement(t);for(var k in a||{})e[k]=a[k];(kids||[]).forEach(function(c){e.appendChild(typeof c==='string'?document.createTextNode(c):c);});return e;}
  function cell(label,input){return el('label',{className:'ato-cell'},[label,input]);}
  function num(v,set){var i=el('input',{type:'number',step:'any',min:'0',value:v});i.oninput=function(){set(i.value===''?'':Number(i.value));};return i;}
  function render(){
    root.innerHTML='';
    Object.keys(data).forEach(function(k){
      var m=META[k]||[k], lab=m[1]||function(i){return '#'+i;}, v=data[k], fs=el('fieldset',{},[el('legend',{},[m[0]])]);
      if(typeof v==='string'){var t=el('input',{className:'ato-text',value:v,type:/Effective$/.test(k)?'date':'text'});t.oninput=function(){data[k]=t.value;};fs.appendChild(t);}
      else if(typeof v==='number'){fs.appendChild(num(v,function(x){data[k]=x;}));}
      else if(typeof v[0]==='number'){var r=el('div',{className:'ato-row'});v.forEach(function(n,i){r.appendChild(cell(lab(i),num(n,function(x){v[i]=x;})));});fs.appendChild(r);}
      else{
        var ak=Object.keys(v[0]).filter(function(x){return x!=='from';})[0];
        v.forEach(function(row,ri){
          var r=el('div',{className:'ato-row'}), d=el('input',{type:'date',value:row.from});d.oninput=function(){row.from=d.value;};
          r.appendChild(cell('Effective from',d));
          row[ak].forEach(function(n,i){r.appendChild(cell(lab(i),num(n,function(x){row[ak][i]=x;})));});
          if(v.length>1){var del=el('button',{type:'button',className:'button-link button-link-delete'},['Remove row']);del.onclick=function(){if(confirm('Remove this effective-dated row?')){v.splice(ri,1);render();}};r.appendChild(del);}
          fs.appendChild(r);
        });
        var add=el('button',{type:'button',className:'button',style:'margin-top:10px'},['Add effective-dated row']);
        add.onclick=function(){var last=v[v.length-1],n={from:''};n[ak]=last[ak].slice();v.push(n);render();};
        fs.appendChild(add);
      }
      root.appendChild(fs);
    });
  }
  render();
  ta.form.addEventListener('submit',function(){ta.value=JSON.stringify(data);});
})();
</script>
<?php }
