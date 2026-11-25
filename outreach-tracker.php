<?php
/**
 * Plugin Name: Outreach Tracker
 * Description: Cold outreach CRM. Accessible from anywhere, mobile-friendly. Data stored in WordPress DB.
 * Version: 1.0.0
 * Author: Amit Jyoti Shaw
 */

if (!defined('ABSPATH')) exit;

define('OT_CAP', 'manage_options');
define('OT_TABLE', 'outreach_businesses');

/* ============================================================
   ACTIVATION — create table + options
============================================================ */
register_activation_hook(__FILE__, 'ot_activate');
function ot_activate() {
    global $wpdb;
    $table = $wpdb->prefix . OT_TABLE;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_name VARCHAR(255) NOT NULL,
        zone VARCHAR(100) DEFAULT '',
        city VARCHAR(150) DEFAULT '',
        industry VARCHAR(100) DEFAULT '',
        website VARCHAR(500) DEFAULT '',
        email VARCHAR(255) NOT NULL,
        status VARCHAR(50) DEFAULT 'Not sent',
        notes TEXT,
        sent_date DATE NULL,
        f1_date DATE NULL,
        f2_date DATE NULL,
        final_date DATE NULL,
        dnc_date DATE NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY email (email),
        KEY status (status),
        KEY city (city)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    if (get_option('ot_done_cities') === false) {
        add_option('ot_done_cities', json_encode([]));
    }
}

/* ============================================================
   ZONES DATA
============================================================ */
function ot_zones() {
    return [
        "1. Southeast" => ["Atlanta GA","Nashville TN","Charlotte NC","Raleigh NC","Tampa FL","Orlando FL","Miami FL","Jacksonville FL","Birmingham AL","Memphis TN","New Orleans LA","Richmond VA","Virginia Beach VA"],
        "2. Texas & South Central" => ["Dallas TX","Fort Worth TX","Houston TX","Austin TX","San Antonio TX","Oklahoma City OK","Tulsa OK","Little Rock AR","Baton Rouge LA","Shreveport LA"],
        "3. Midwest" => ["Chicago IL","Detroit MI","Minneapolis MN","St. Louis MO","Kansas City MO","Indianapolis IN","Columbus OH","Cincinnati OH","Cleveland OH","Milwaukee WI"],
        "4. Northeast" => ["New York NY","Boston MA","Philadelphia PA","Washington DC","Baltimore MD","Pittsburgh PA","Hartford CT"],
        "5. Mountain West" => ["Phoenix AZ","Denver CO","Las Vegas NV","Salt Lake City UT","Tucson AZ","Albuquerque NM","Boise ID"],
        "6. West Coast" => ["Los Angeles CA","San Diego CA","San Francisco CA","Sacramento CA","Portland OR","Seattle WA","Fresno CA","Bakersfield CA"],
    ];
}

/* ============================================================
   REST API
============================================================ */
add_action('rest_api_init', 'ot_register_routes');
function ot_register_routes() {
    $ns = 'outreach-tracker/v1';
    $perm = function() { return current_user_can(OT_CAP); };

    register_rest_route($ns, '/businesses', [
        ['method'=>'GET',  'callback'=>'ot_api_list',   'permission_callback'=>$perm],
        ['method'=>'POST', 'callback'=>'ot_api_create', 'permission_callback'=>$perm],
    ]);
    register_rest_route($ns, '/businesses/(?P<id>\d+)', [
        ['method'=>'POST',   'callback'=>'ot_api_update', 'permission_callback'=>$perm],
        ['method'=>'DELETE', 'callback'=>'ot_api_delete', 'permission_callback'=>$perm],
    ]);
    register_rest_route($ns, '/cities', [
        ['method'=>'GET', 'callback'=>'ot_api_cities', 'permission_callback'=>$perm],
    ]);
    register_rest_route($ns, '/cities/toggle', [
        ['method'=>'POST', 'callback'=>'ot_api_toggle_city', 'permission_callback'=>$perm],
    ]);
}

function ot_api_list() {
    global $wpdb;
    $table = $wpdb->prefix . OT_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC", ARRAY_A);
    return rest_ensure_response($rows);
}

function ot_api_create(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . OT_TABLE;
    $b = $req->get_json_params();
    if (empty($b['business_name']) || empty($b['email'])) {
        return new WP_Error('missing', 'Business name and email are required', ['status'=>400]);
    }
    $wpdb->insert($table, [
        'business_name' => sanitize_text_field($b['business_name']),
        'zone'          => sanitize_text_field($b['zone'] ?? ''),
        'city'          => sanitize_text_field($b['city'] ?? ''),
        'industry'      => sanitize_text_field($b['industry'] ?? ''),
        'website'       => esc_url_raw($b['website'] ?? ''),
        'email'         => sanitize_email($b['email']),
        'status'        => 'Not sent',
        'notes'         => sanitize_textarea_field($b['notes'] ?? ''),
        'created_at'    => current_time('mysql'),
    ]);
    return rest_ensure_response(['id' => $wpdb->insert_id]);
}

function ot_api_update(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . OT_TABLE;
    $id = (int)$req['id'];
    $b = $req->get_json_params();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
    if (!$existing) return new WP_Error('not_found','Business not found',['status'=>404]);

    $update = [];
    foreach (['business_name','zone','city','industry','website','email','notes','status'] as $f) {
        if (array_key_exists($f, $b)) {
            $update[$f] = $f === 'email' ? sanitize_email($b[$f]) :
                         ($f === 'notes' ? sanitize_textarea_field($b[$f]) :
                         ($f === 'website' ? esc_url_raw($b[$f]) : sanitize_text_field($b[$f])));
        }
    }

    // Auto date logic
    if (!empty($b['status'])) {
        $s = $b['status'];
        $today = current_time('Y-m-d');
        $plus = function($n) { return date('Y-m-d', strtotime("+$n days", current_time('timestamp'))); };
        if ($s === 'Sent' && empty($existing['sent_date'])) {
            $update['sent_date'] = $today;
            $update['f1_date'] = $plus(4);
            $update['f2_date'] = $plus(8);
            $update['final_date'] = $plus(15);
        }
        if ($s === 'DNC' && empty($existing['dnc_date'])) {
            $update['dnc_date'] = $today;
        }
    }

    $wpdb->update($table, $update, ['id' => $id]);
    return rest_ensure_response(['ok' => true]);
}

function ot_api_delete(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . OT_TABLE;
    $wpdb->delete($table, ['id' => (int)$req['id']]);
    return rest_ensure_response(['ok' => true]);
}

function ot_api_cities() {
    $cities = json_decode(get_option('ot_done_cities', '[]'), true) ?: [];
    return rest_ensure_response($cities);
}

function ot_api_toggle_city(WP_REST_Request $req) {
    $city = sanitize_text_field($req->get_param('city'));
    $cities = json_decode(get_option('ot_done_cities', '[]'), true) ?: [];
    $i = array_search($city, $cities);
    if ($i !== false) unset($cities[$i]);
    else $cities[] = $city;
    update_option('ot_done_cities', json_encode(array_values($cities)));
    return rest_ensure_response(['done' => $i === false]);
}

/* ============================================================
   ADMIN MENU
============================================================ */
add_action('admin_menu', 'ot_admin_menu');
function ot_admin_menu() {
    add_menu_page(
        'Outreach Tracker', 'Outreach Tracker',
        OT_CAP, 'outreach-tracker',
        'ot_render_app', 'dashicons-email-alt', 25
    );
}

/* ============================================================
   SHORTCODE
============================================================ */
add_shortcode('outreach_tracker', 'ot_shortcode');
function ot_shortcode() {
    if (!current_user_can(OT_CAP)) {
        return '<div style="padding:40px;text-align:center;font-family:sans-serif">Please log in as an administrator to view the tracker.</div>';
    }
    ob_start(); ot_render_app(); return ob_get_clean();
}

/* ============================================================
   APP RENDER
============================================================ */
function ot_render_app() {
    $zones = ot_zones();
    $rest_url = esc_url_raw(rest_url('outreach-tracker/v1'));
    $nonce = wp_create_nonce('wp_rest');
    ?>
    <style>
    .ot-wrap{max-width:1400px;margin:0 auto;padding:16px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1a2028;font-size:14px;line-height:1.5}
    .ot-header{background:#0d4e73;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border-radius:8px;margin-bottom:14px;flex-wrap:wrap;gap:10px}
    .ot-header h1{font-size:18px;font-weight:700;color:#fff;margin:0}
    .ot-header .ot-sub{font-size:12px;opacity:.8;margin-top:2px}
    .ot-actions{display:flex;gap:8px;flex-wrap:wrap}
    .ot-btn{cursor:pointer;border:none;border-radius:5px;padding:9px 14px;font-size:13px;font-weight:600;font-family:inherit;transition:opacity .15s;white-space:nowrap}
    .ot-btn:hover{opacity:.88}
    .ot-primary{background:#0d8a9e;color:#fff}
    .ot-secondary{background:#fff;color:#0d4e73;border:1px solid #d5dde3}
    .ot-danger{background:#b42318;color:#fff}
    .ot-sm{padding:5px 10px;font-size:12px}
    .ot-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
    .ot-stat{background:#fff;border:1px solid #e1e6ea;border-radius:8px;padding:12px 14px}
    .ot-stat .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#6b7780;font-weight:700}
    .ot-stat .val{font-size:22px;font-weight:700;margin-top:4px;color:#0d4e73}
    .ot-tabs{display:flex;gap:4px;margin-bottom:14px;border-bottom:2px solid #e1e6ea;overflow-x:auto}
    .ot-tab{padding:10px 16px;background:transparent;color:#6b7780;font-weight:600;border-radius:6px 6px 0 0;border:none;cursor:pointer;font-size:13px;font-family:inherit;white-space:nowrap}
    .ot-tab.active{background:#fff;color:#0d4e73;border:1px solid #e1e6ea;border-bottom-color:#fff;margin-bottom:-2px}
    .ot-panel{background:#fff;border:1px solid #e1e6ea;border-radius:8px;padding:16px;display:none}
    .ot-panel.active{display:block}
    .ot-controls{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;align-items:center}
    .ot-controls input,.ot-controls select{padding:8px 10px;border:1px solid #d5dde3;border-radius:5px;font-size:13px;font-family:inherit;background:#fff}
    .ot-controls input[type=text]{min-width:200px;flex:1;max-width:300px}
    .ot-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .ot-table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
    .ot-table th{text-align:left;padding:10px 8px;background:#f7f9fa;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#6b7780;border-bottom:2px solid #e1e6ea;font-weight:700;white-space:nowrap}
    .ot-table td{padding:10px 8px;border-bottom:1px solid #eef2f5;vertical-align:top}
    .ot-table tr:hover td{background:#fafcfd}
    .ot-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
    .ot-badge.notsent{background:#e8edf1;color:#4a5761}
    .ot-badge.sent{background:#e1eefb;color:#0b5cab}
    .ot-badge.f1{background:#fdf0d5;color:#8a5a00}
    .ot-badge.f2{background:#fce4d6;color:#a04000}
    .ot-badge.replied{background:#e2f5e9;color:#0a6b34}
    .ot-badge.demo{background:#e8e2f7;color:#4a2ba6}
    .ot-badge.won{background:#d1f0d8;color:#0a5c2b}
    .ot-badge.lost{background:#fadcd9;color:#8a1c11}
    .ot-badge.dnc{background:#1a1a1a;color:#fff}
    .ot-link{color:#0d8a9e;text-decoration:none;font-weight:600}
    .ot-link:hover{text-decoration:underline}
    .ot-empty{text-align:center;padding:40px;color:#8a97a0;font-size:13px}
    .ot-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-start;justify-content:center;z-index:999999;padding:20px;overflow-y:auto}
    .ot-modal.active{display:flex}
    .ot-modal-box{background:#fff;border-radius:10px;padding:22px;max-width:600px;width:100%;margin-top:40px}
    .ot-modal-box h2{font-size:18px;margin:0 0 16px;color:#0d4e73}
    .ot-frow{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
    .ot-frow.full{grid-template-columns:1fr}
    .ot-frow label{display:block;font-size:12px;font-weight:600;color:#4a5761;margin-bottom:4px}
    .ot-frow input,.ot-frow select,.ot-frow textarea{width:100%;padding:8px 10px;border:1px solid #d5dde3;border-radius:5px;font-size:13px;font-family:inherit;background:#fff}
    .ot-frow textarea{resize:vertical;min-height:70px}
    .ot-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
    .ot-zone-card{background:#fafcfd;border:1px solid #e1e6ea;border-radius:8px;padding:12px;margin-bottom:10px}
    .ot-zone-card h3{font-size:14px;color:#0d4e73;margin:0 0 8px}
    .ot-city-list{display:flex;flex-wrap:wrap;gap:6px}
    .ot-city{background:#fff;border:1px solid #d5dde3;border-radius:20px;padding:4px 12px;font-size:11px;color:#4a5761;font-weight:600;cursor:pointer;user-select:none}
    .ot-city.done{background:#d1f0d8;border-color:#a8dfb9;color:#0a5c2b;text-decoration:line-through}
    .ot-city:hover{border-color:#0d8a9e}
    .ot-row-actions{display:flex;gap:4px;flex-wrap:wrap}
    .ot-icon-btn{background:transparent;border:1px solid #d5dde3;border-radius:4px;padding:3px 7px;font-size:11px;color:#6b7780;cursor:pointer;font-family:inherit}
    .ot-icon-btn:hover{background:#f4f6f8;color:#b42318;border-color:#b42318}
    .ot-today{background:#fff9e6;border-left:3px solid #d4a017;padding:12px 14px;border-radius:6px;margin-bottom:14px;font-size:13px}
    .ot-today strong{color:#8a5a00}
    .ot-loading{text-align:center;padding:30px;color:#8a97a0;font-size:13px}
    @media(max-width:782px){
      .ot-stats{grid-template-columns:repeat(3,1fr)}
      .ot-frow{grid-template-columns:1fr}
      .ot-header{flex-direction:column;align-items:flex-start}
      .ot-table{font-size:12px;min-width:700px}
      .ot-table th,.ot-table td{padding:8px 5px}
      .ot-controls input[type=text]{min-width:140px}
    }
    @media(max-width:480px){
      .ot-stats{grid-template-columns:repeat(2,1fr)}
      .ot-stat .val{font-size:18px}
    }
    </style>

    <div class="ot-wrap" id="ot-app">
      <div class="ot-header">
        <div>
          <h1>Outreach Tracker</h1>
          <div class="ot-sub">Amit Jyoti Shaw · Cold email system</div>
        </div>
        <div class="ot-actions">
          <button class="ot-btn ot-primary" onclick="OT.openModal()">+ Add Business</button>
        </div>
      </div>

      <div class="ot-stats">
        <div class="ot-stat"><div class="lbl">Total</div><div class="val" id="otStTotal">0</div></div>
        <div class="ot-stat"><div class="lbl">Sent</div><div class="val" id="otStSent">0</div></div>
        <div class="ot-stat"><div class="lbl">Replied</div><div class="val" id="otStReplied">0</div></div>
        <div class="ot-stat"><div class="lbl">Demo</div><div class="val" id="otStDemo">0</div></div>
        <div class="ot-stat"><div class="lbl">Won</div><div class="val" id="otStWon">0</div></div>
        <div class="ot-stat"><div class="lbl">Reply %</div><div class="val" id="otStRate">0%</div></div>
      </div>

      <div class="ot-tabs">
        <button class="ot-tab active" onclick="OT.tab('dash',this)">Dashboard</button>
        <button class="ot-tab" onclick="OT.tab('biz',this)">All Businesses</button>
        <button class="ot-tab" onclick="OT.tab('zones',this)">Zones</button>
        <button class="ot-tab" onclick="OT.tab('dnc',this)">Do Not Contact</button>
      </div>

      <div class="ot-panel active" id="otPanelDash">
        <div class="ot-today" id="otTodayBox"><strong>Today's tasks</strong> — loading…</div>
        <h3 style="font-size:14px;color:#0d4e73;margin:0 0 10px">Follow-ups due</h3>
        <div id="otFollowupWrap"><div class="ot-loading">Loading…</div></div>
      </div>

      <div class="ot-panel" id="otPanelBiz">
        <div class="ot-controls">
          <input type="text" id="otSearch" placeholder="Search business, city, email..." oninput="OT.renderTable()">
          <select id="otFilterZone" onchange="OT.renderTable()"><option value="">All zones</option></select>
          <select id="otFilterStatus" onchange="OT.renderTable()">
            <option value="">All statuses</option>
            <option>Not sent</option><option>Sent</option><option>Follow-up 1</option>
            <option>Follow-up 2</option><option>Replied</option><option>Demo sent</option>
            <option>Won</option><option>Lost</option>
          </select>
        </div>
        <div id="otTableWrap"><div class="ot-loading">Loading…</div></div>
      </div>

      <div class="ot-panel" id="otPanelZones">
        <p style="font-size:13px;color:#6b7780;margin:0 0 14px">Work one zone at a time. Click a city to mark it done.</p>
        <div id="otZonesWrap"></div>
      </div>

      <div class="ot-panel" id="otPanelDnc">
        <p style="font-size:13px;color:#6b7780;margin:0 0 14px">Never contact anyone here again.</p>
        <div id="otDncWrap"><div class="ot-loading">Loading…</div></div>
      </div>
    </div>

    <div class="ot-modal" id="otModal">
      <div class="ot-modal-box">
        <h2 id="otModalTitle">Add Business</h2>
        <input type="hidden" id="otEditId">
        <div class="ot-frow">
          <div><label>Zone</label><select id="otZone"></select></div>
          <div><label>City</label><input type="text" id="otCity" placeholder="e.g. Antioch, CA"></div>
        </div>
        <div class="ot-frow">
          <div><label>Industry</label><select id="otIndustry">
            <option>Home Services</option><option>Restaurant</option><option>Dental</option>
            <option>Chiropractic</option><option>Med Spa</option><option>Salon / Barbershop</option>
            <option>Gym / Fitness</option><option>Veterinary</option><option>Auto Repair</option>
            <option>Legal</option><option>Real Estate</option><option>Other</option>
          </select></div>
          <div><label>Business Name</label><input type="text" id="otName" placeholder="e.g. In Demand Plumbing"></div>
        </div>
        <div class="ot-frow">
          <div><label>Website</label><input type="text" id="otWeb" placeholder="https://..."></div>
          <div><label>Email</label><input type="text" id="otEmail" placeholder="owner@..."></div>
        </div>
        <div class="ot-frow full">
          <div><label>Notes</label><textarea id="otNotes" placeholder="What you noticed on their site..."></textarea></div>
        </div>
        <div class="ot-modal-actions">
          <button class="ot-btn ot-secondary" onclick="OT.closeModal()">Cancel</button>
          <button class="ot-btn ot-primary" onclick="OT.save()">Save</button>
        </div>
      </div>
    </div>

    <script>
    window.OT_CONFIG = {
      rest: <?php echo json_encode($rest_url); ?>,
      nonce: <?php echo json_encode($nonce); ?>,
      zones: <?php echo json_encode($zones); ?>
    };

    window.OT = (function(){
      const C = window.OT_CONFIG;
      let businesses = [];
      let doneCities = [];

      async function api(path, method='GET', body=null){
        const opts = {
          method,
          headers: { 'Content-Type':'application/json', 'X-WP-Nonce': C.nonce },
          credentials: 'same-origin'
        };
        if (body) opts.body = JSON.stringify(body);
        const r = await fetch(C.rest + path, opts);
        if (!r.ok) throw new Error('Request failed');
        return r.json();
      }

      function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);}

      function statusClass(s){
        return {'Not sent':'notsent','Sent':'sent','Follow-up 1':'f1','Follow-up 2':'f2','Replied':'replied','Demo sent':'demo','Won':'won','Lost':'lost','DNC':'dnc'}[s] || 'notsent';
      }

      async function load(){
        [businesses, doneCities] = await Promise.all([ api('/businesses'), api('/cities') ]);
        renderStats(); renderTable(); renderDash(); renderZones(); renderDNC();
      }

      function renderStats(){
        const b = businesses.filter(x => x.status !== 'DNC');
        document.getElementById('otStTotal').textContent = b.length;
        document.getElementById('otStSent').textContent = b.filter(x=>x.status!=='Not sent').length;
        document.getElementById('otStReplied').textContent = b.filter(x=>['Replied','Demo sent','Won'].includes(x.status)).length;
        document.getElementById('otStDemo').textContent = b.filter(x=>['Demo sent','Won'].includes(x.status)).length;
        document.getElementById('otStWon').textContent = b.filter(x=>x.status==='Won').length;
        const sent = b.filter(x=>x.status!=='Not sent').length;
        const replied = b.filter(x=>['Replied','Demo sent','Won','Lost'].includes(x.status)).length;
        document.getElementById('otStRate').textContent = sent ? Math.round(replied/sent*100)+'%' : '0%';
      }

      function renderTable(){
        const search = (document.getElementById('otSearch').value||'').toLowerCase();
        const fz = document.getElementById('otFilterZone').value;
        const fs = document.getElementById('otFilterStatus').value;
        let rows = businesses.filter(x=>x.status!=='DNC');
        if (fz) rows = rows.filter(x=>x.zone===fz);
        if (fs) rows = rows.filter(x=>x.status===fs);
        if (search) rows = rows.filter(x=>((x.business_name||'')+(x.city||'')+(x.email||'')+(x.notes||'')).toLowerCase().includes(search));
        const wrap = document.getElementById('otTableWrap');
        if (!rows.length){ wrap.innerHTML = '<div class="ot-empty">No businesses yet. Click <strong>+ Add Business</strong> to start.</div>'; return; }
        wrap.innerHTML = '<div class="ot-table-wrap"><table class="ot-table"><thead><tr>' +
          '<th>Business</th><th>City</th><th>Industry</th><th>Email</th><th>Status</th><th>Sent</th><th>Actions</th>' +
          '</tr></thead><tbody>' + rows.map(b => {
            const statuses = ['Not sent','Sent','Follow-up 1','Follow-up 2','Replied','Demo sent','Won','Lost'];
            return '<tr>' +
              '<td><strong>'+esc(b.business_name)+'</strong>'+(b.website?'<br><a class="ot-link" href="'+esc(b.website)+'" target="_blank">site</a>':'')+'</td>' +
              '<td>'+esc(b.city)+'</td>' +
              '<td>'+esc(b.industry)+'</td>' +
              '<td><a class="ot-link" href="mailto:'+esc(b.email)+'">'+esc(b.email)+'</a></td>' +
              '<td><span class="ot-badge '+statusClass(b.status)+'">'+esc(b.status)+'</span></td>' +
              '<td>'+esc(b.sent_date||'—')+'</td>' +
              '<td><div class="ot-row-actions">' +
                '<select onchange="OT.setStatus('+b.id+',this.value)" style="font-size:11px;padding:3px;border-radius:4px;border:1px solid #d5dde3">' +
                  statuses.map(s => '<option '+(b.status===s?'selected':'')+'>'+s+'</option>').join('') +
                '</select>' +
                '<button class="ot-icon-btn" onclick="OT.openModal('+b.id+')">Edit</button>' +
                '<button class="ot-icon-btn" onclick="OT.toDNC('+b.id+')">DNC</button>' +
                '<button class="ot-icon-btn" onclick="OT.remove('+b.id+')">×</button>' +
              '</div></td>' +
            '</tr>';
          }).join('') + '</tbody></table></div>';
      }

      function renderDash(){
        const today = new Date().toISOString().slice(0,10);
        const due = businesses.filter(b => {
          if (b.status==='Sent' && b.f1_date && b.f1_date<=today) return true;
          if (b.status==='Follow-up 1' && b.f2_date && b.f2_date<=today) return true;
          if (b.status==='Follow-up 2' && b.final_date && b.final_date<=today) return true;
          return false;
        });
        const box = document.getElementById('otTodayBox');
        box.innerHTML = due.length
          ? '<strong>'+due.length+' follow-up'+(due.length>1?'s':'')+' due today</strong>'
          : '<strong>Today\'s tasks</strong> — nothing due. Nice.';
        const wrap = document.getElementById('otFollowupWrap');
        if (!due.length){ wrap.innerHTML = '<div class="ot-empty">No follow-ups due.</div>'; return; }
        wrap.innerHTML = '<div class="ot-table-wrap"><table class="ot-table"><thead><tr><th>Business</th><th>Email</th><th>Status</th><th>Due</th><th></th></tr></thead><tbody>' +
          due.map(b => {
            let next='', label='', dueDate='';
            if (b.status==='Sent'){ label='Follow-up 1'; next='Follow-up 1'; dueDate=b.f1_date; }
            else if (b.status==='Follow-up 1'){ label='Follow-up 2'; next='Follow-up 2'; dueDate=b.f2_date; }
            else { label='Final'; next='Follow-up 2'; dueDate=b.final_date; }
            return '<tr>' +
              '<td><strong>'+esc(b.business_name)+'</strong></td>' +
              '<td><a class="ot-link" href="mailto:'+esc(b.email)+'">'+esc(b.email)+'</a></td>' +
              '<td><span class="ot-badge '+statusClass(b.status)+'">'+esc(b.status)+'</span></td>' +
              '<td>'+esc(dueDate)+'</td>' +
              '<td><button class="ot-btn ot-primary ot-sm" onclick="OT.setStatus('+b.id+',\''+next+'\')">Mark '+label+' sent</button></td>' +
            '</tr>';
          }).join('') + '</tbody></table></div>';
      }

      function renderZones(){
        const wrap = document.getElementById('otZonesWrap');
        wrap.innerHTML = Object.entries(C.zones).map(([zone, cities]) => {
          const done = cities.filter(c => doneCities.includes(c)).length;
          return '<div class="ot-zone-card">' +
            '<h3>'+esc(zone)+' <span style="font-size:11px;color:#6b7780;font-weight:400">— '+done+'/'+cities.length+' cities done</span></h3>' +
            '<div class="ot-city-list">' +
              cities.map(c => {
                const isDone = doneCities.includes(c);
                const count = businesses.filter(b => b.city === c).length;
                return '<span class="ot-city '+(isDone?'done':'')+'" onclick="OT.toggleCity(\''+esc(c).replace(/'/g,'\\\'')+'\')">'+esc(c)+(count?' ('+count+')':'')+'</span>';
              }).join('') +
            '</div></div>';
        }).join('');
      }

      function renderDNC(){
        const dnc = businesses.filter(b => b.status === 'DNC');
        const wrap = document.getElementById('otDncWrap');
        if (!dnc.length){ wrap.innerHTML = '<div class="ot-empty">Do Not Contact list is empty.</div>'; return; }
        wrap.innerHTML = '<div class="ot-table-wrap"><table class="ot-table"><thead><tr><th>Business</th><th>Email</th><th>Added</th><th></th></tr></thead><tbody>' +
          dnc.map(b => '<tr>' +
            '<td><strong>'+esc(b.business_name)+'</strong></td>' +
            '<td>'+esc(b.email)+'</td>' +
            '<td>'+esc(b.dnc_date||'—')+'</td>' +
            '<td><button class="ot-icon-btn" onclick="OT.remove('+b.id+')">Remove</button></td>' +
          '</tr>').join('') + '</tbody></table></div>';
      }

      // ---- actions ----
      async function setStatus(id, status){
        await api('/businesses/'+id, 'POST', { status });
        await load();
      }

      async function toggleCity(city){
        await api('/cities/toggle', 'POST', { city });
        doneCities = await api('/cities');
        renderZones();
      }

      async function remove(id){
        if (!confirm('Delete this business?')) return;
        await api('/businesses/'+id, 'DELETE');
        await load();
      }

      async function toDNC(id){
        if (!confirm('Move to Do Not Contact?')) return;
        await setStatus(id, 'DNC');
      }

      function openModal(id){
        const modal = document.getElementById('otModal');
        const title = document.getElementById('otModalTitle');
        const editId = document.getElementById('otEditId');
        if (id){
          const b = businesses.find(x => x.id == id);
          if (!b) return;
          title.textContent = 'Edit Business';
          editId.value = id;
          document.getElementById('otZone').value = b.zone || '';
          document.getElementById('otCity').value = b.city || '';
          document.getElementById('otIndustry').value = b.industry || 'Other';
          document.getElementById('otName').value = b.business_name || '';
          document.getElementById('otWeb').value = b.website || '';
          document.getElementById('otEmail').value = b.email || '';
          document.getElementById('otNotes').value = b.notes || '';
        } else {
          title.textContent = 'Add Business';
          editId.value = '';
          ['otCity','otName','otWeb','otEmail','otNotes'].forEach(i => document.getElementById(i).value = '');
        }
        modal.classList.add('active');
      }

      function closeModal(){ document.getElementById('otModal').classList.remove('active'); }

      async function save(){
        const id = document.getElementById('otEditId').value;
        const payload = {
          zone: document.getElementById('otZone').value,
          city: document.getElementById('otCity').value.trim(),
          industry: document.getElementById('otIndustry').value,
          business_name: document.getElementById('otName').value.trim(),
          website: document.getElementById('otWeb').value.trim(),
          email: document.getElementById('otEmail').value.trim(),
          notes: document.getElementById('otNotes').value.trim()
        };
        if (!payload.business_name){ alert('Business name is required'); return; }
        if (!payload.email){ alert('Email is required'); return; }
        try {
          if (id) await api('/businesses/'+id, 'POST', payload);
          else     await api('/businesses', 'POST', payload);
          closeModal();
          await load();
        } catch(e){ alert('Save failed. Please try again.'); }
      }

      function tab(name, el){
        document.querySelectorAll('.ot-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.ot-tab').forEach(t => t.classList.remove('active'));
        const map = { dash:'otPanelDash', biz:'otPanelBiz', zones:'otPanelZones', dnc:'otPanelDnc' };
        document.getElementById(map[name]).classList.add('active');
        el.classList.add('active');
      }

      // init
      document.addEventListener('DOMContentLoaded', function(){
        const zSel = document.getElementById('otZone');
        zSel.innerHTML = Object.keys(C.zones).map(z => '<option>'+esc(z)+'</option>').join('');
        const fz = document.getElementById('otFilterZone');
        fz.innerHTML = '<option value="">All zones</option>' + Object.keys(C.zones).map(z => '<option>'+esc(z)+'</option>').join('');
        load().catch(e => console.error(e));
      });

      return { openModal, closeModal, save, setStatus, remove, toDNC, toggleCity, renderTable, tab, load };
    })();
    </script>
    <?php
}