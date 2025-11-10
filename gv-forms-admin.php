<?php
/**
 * GV Forms – Admin (visual builder)
 * File: gv-forms-admin.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GV_Contact_Form_Admin {

    /* -----------------------------
     * Option keys
     * --------------------------- */
    const OPT_FIELDS      = 'gv_forms_fields';
    const OPT_LABEL_COLOR = 'gv_forms_label_color';

    // Title + visual
    const OPT_TITLE_TEXT   = 'gv_forms_title_text';
    const OPT_TITLE_ALIGN  = 'gv_forms_title_align';  // left|center|right
    const OPT_TITLE_COLOR  = 'gv_forms_title_color';
    const OPT_BG_COLOR     = 'gv_forms_bg_color';
    const OPT_GLASS_BLUR   = 'gv_forms_glass_blur';
    const OPT_BORDER_WIDTH = 'gv_forms_border_width';
    const OPT_BORDER_STYLE = 'gv_forms_border_style';
    const OPT_BORDER_COLOR = 'gv_forms_border_color';

    // New 3.0 Style Options
    const OPT_PADDING       = 'gv_forms_padding';
    const OPT_BORDER_RADIUS = 'gv_forms_border_radius';
    const OPT_SHADOW        = 'gv_forms_shadow';

    /* -----------------------------
     * Boot
     * --------------------------- */
    public function __construct() {
        add_action('admin_menu', [ $this, 'menu' ]);
        add_action('admin_enqueue_scripts', [ $this, 'assets' ]);

        add_action('wp_ajax_gv_save_fields', [ $this, 'ajax_save_fields' ]);
        add_action('admin_init', [ $this, 'maybe_activation_redirect' ]);

        // Single message view enhancements
        add_action('add_meta_boxes_gv_message', [ $this, 'add_message_metaboxes' ]);
        add_action('admin_post_gv_msg_export_eml', [ $this, 'export_eml' ]);
        add_action('admin_post_gv_msg_copy_json', [ $this, 'export_json' ]);
    }

    /* ---------- Meta boxes ---------- */
    public function add_message_metaboxes() {
        add_meta_box('gv_msg_sender',  'Sender',      [ $this,'box_sender'   ], 'gv_message', 'normal', 'high');
        add_meta_box('gv_msg_payload', 'Form Data',   [ $this,'box_payload'  ], 'gv_message', 'normal', 'default');
        add_meta_box('gv_msg_tech',    'Technical',   [ $this,'box_technical'], 'gv_message', 'side',   'default');
        add_meta_box('gv_msg_actions', 'Actions',     [ $this,'box_actions'  ], 'gv_message', 'side',   'high');
    }

    /* Helpers to extract data safely from meta/content */
    private function gv_msg_collect(\WP_Post $post) {
        $m = function($k,$d=null) use ($post){ $v=get_post_meta($post->ID,$k,true); return $v!==''?$v:$d; };

        $out = [
            'name'      => $m('_gv_name') ?: $m('name'),
            'email'     => $m('_gv_email') ?: $m('email'),
            'company'   => $m('_gv_company') ?: $m('company'),
            'phone'     => $m('_gv_phone') ?: $m('phone'),
            'ip'        => $m('_gv_ip') ?: $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'        => $m('_gv_ua'),
            'referer'   => $m('_gv_referer') ?: $m('referer'),
            'page'      => $m('_gv_page') ?: $m('page_url'),
            'score'     => $m('_gv_spam_score'),
            'honeypot'  => $m('_gv_honeypot') ? 'tripped' : '',
            'attachments' => (array) $m('_gv_files', []),
        ];

        // payload: try meta first then JSON or key: value lines in content
        $payload = $m('_gv_payload');
        if (!$payload) {
            $raw = trim((string)$post->post_content);
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j)) $payload = $j;
                else {
                    $arr = [];
                    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                        if (strpos($line, ':')!==false) {
                            [$k,$v] = array_map('trim', explode(':', $line, 2));
                            if ($k!=='') $arr[$k]=$v;
                        }
                    }
                    if ($arr) $payload = $arr;
                }
            }
        }
        if (!is_array($payload)) $payload = [];

        foreach (['name','email','company','phone','message'] as $k) {
            if (empty($out[$k]) && isset($payload[$k])) $out[$k] = $payload[$k];
        }

        return [$out, $payload];
    }

    /* ---------- Renderers ---------- */
    public function box_sender(\WP_Post $post) {
        list($d,) = $this->gv_msg_collect($post);
        $name    = esc_html($d['name'] ?: '(unknown)');
        $email   = sanitize_email($d['email']);
        $company = esc_html($d['company'] ?: '');
        $phone   = esc_html($d['phone'] ?: '');
        $when    = esc_html(get_the_time('Y-m-d H:i:s', $post));
        $page    = esc_url($d['page'] ?: $d['referer'] ?: '');

        echo '<div class="gv-msg-grid">';
        echo '<div><span class="lbl">Name</span><span class="val">'.$name.'</span></div>';
        echo '<div><span class="lbl">Email</span><span class="val">'.($email ? '<a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a>' : '—').'</span></div>';
        if ($company) echo '<div><span class="lbl">Company</span><span class="val">'.$company.'</span></div>';
        if ($phone)   echo '<div><span class="lbl">Phone</span><span class="val">'.$phone.'</span></div>';
        echo '<div><span class="lbl">Received</span><span class="val">'.$when.'</span></div>';
        echo '<div><span class="lbl">Form Page</span><span class="val">'.($page?'<a href="'.$page.'" target="_blank" rel="noopener">Open page</a>':'—').'</span></div>';
        echo '</div>';
    }

    public function box_payload(\WP_Post $post) {
        list($d, $payload) = $this->gv_msg_collect($post);
        if (!$payload) { echo '<p>No structured fields stored.</p>'; return; }

        echo '<table class="widefat fixed striped gv-msg-table"><tbody>';
        foreach ($payload as $k=>$v) {
            $k = esc_html(ucfirst(str_replace('_',' ',$k)));
            if (is_array($v)) $v = implode(', ', array_map('sanitize_text_field',$v));
            $v = esc_html((string)$v);
            echo "<tr><th scope='row'>{$k}</th><td>{$v}</td></tr>";
        }
        echo '</tbody></table>';
    }

    public function box_technical(\WP_Post $post) {
        list($d,) = $this->gv_msg_collect($post);

        echo '<ul class="gv-kv">';
        echo '<li><span>IP</span><code>'.esc_html($d['ip'] ?: '—').'</code></li>';
        echo '<li><span>User-Agent</span><code>'.esc_html($d['ua'] ?: '—').'</code></li>';
        echo '<li><span>Referrer</span><code>'.esc_html($d['referer'] ?: '—').'</code></li>';
        if ($d['score']!=='')  echo '<li><span>Spam score</span><code>'.esc_html($d['score']).'</code></li>';
        if ($d['honeypot'])    echo '<li><span>Honeypot</span><code>tripped</code></li>';
        echo '</ul>';

        if (!empty($d['attachments'])) {
            echo '<h4 class="gv-sub">Attachments</h4><ul class="gv-files">';
            foreach ((array)$d['attachments'] as $url) {
                $u = esc_url($url);
                echo "<li><a href='$u' target='_blank' rel='noopener'>".basename(parse_url($u,PHP_URL_PATH))."</a></li>";
            }
            echo '</ul>';
        }
    }

    public function box_actions(\WP_Post $post) {
        $eml  = wp_nonce_url(admin_url('admin-post.php?action=gv_msg_export_eml&post_id='.$post->ID), 'gv_msg_export_eml_'.$post->ID);
        $json = wp_nonce_url(admin_url('admin-post.php?action=gv_msg_copy_json&post_id='.$post->ID), 'gv_msg_copy_json_'.$post->ID);

        $subject = rawurlencode( sprintf('Re: %s', get_the_title($post)) );
        $body    = rawurlencode( "Hi,\n\n> ".trim(wp_strip_all_tags($post->post_content))."\n\n—" );
        echo '<a class="button button-primary button-hero" href="mailto:?subject='.$subject.'&body='.$body.'">Reply via email</a>';
        echo '<p><a class="button" href="'.$eml.'">Download .eml</a> <a class="button" href="'.$json.'">Export JSON</a></p>';
        echo '<p class="description">Files are generated on the fly; nothing is stored.</p>';
    }

    /* ---------- Exporters ---------- */
    public function export_eml() {
        $id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$id || !current_user_can('edit_post',$id)) wp_die('Denied');
        check_admin_referer('gv_msg_export_eml_'.$id);

        $p = get_post($id);
        if (!$p || $p->post_type!=='gv_message') wp_die('Not found');

        $from = get_bloginfo('admin_email');
        $subject = 'Message: '.$p->post_title;
        $date = gmdate('r', strtotime($p->post_date_gmt.' GMT'));
        $body = wp_strip_all_tags($p->post_content);

        header('Content-Type: message/rfc822; charset=UTF-8');
        header('Content-Disposition: attachment; filename="gv-message-'.$id.'.eml"');

        echo "From: {$from}\r\n";
        echo "Subject: {$subject}\r\n";
        echo "Date: {$date}\r\n";
        echo "MIME-Version: 1.0\r\n";
        echo "Content-Type: text/plain; charset=UTF-8\r\n";
        echo "\r\n";
        echo $body;
        exit;
    }

    public function export_json() {
        $id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$id || !current_user_can('edit_post',$id)) wp_die('Denied');
        check_admin_referer('gv_msg_copy_json_'.$id);

        $p = get_post($id);
        list($summary,$payload) = $this->gv_msg_collect($p);
        $data = [
            'id'      => $id,
            'title'   => $p->post_title,
            'created' => $p->post_date_gmt,
            'summary' => $summary,
            'payload' => $payload,
            'raw'     => $p->post_content,
        ];

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="gv-message-'.$id.'.json');
        echo wp_json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* -----------------------------
     * Activation redirect
     * --------------------------- */
    public function maybe_activation_redirect() {
        if ( get_transient( '_gv_forms_activation' ) ) {
            delete_transient( '_gv_forms_activation' );
            if ( current_user_can( 'manage_options' ) && ! isset( $_GET['activate-multi'] ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=gvforms' ) );
                exit;
            }
        }
    }

    /* -----------------------------
     * Menu (top-level + Builder)
     * --------------------------- */
    public function menu() {
        add_menu_page(
            'GV Forms',
            'GV Forms',
            'manage_options',
            'gvforms',
            [ $this, 'page_builder' ],
            'dashicons-feedback',
            57
        );

        add_submenu_page(
            'gvforms',
            'Builder',
            'Builder',
            'manage_options',
            'gvforms',
            [ $this, 'page_builder' ]
        );
    }

    /* -----------------------------
     * Assets (only on our page)
     * --------------------------- */
    public function assets( $hook ) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // Single Message editor styling
        if ( $screen && $screen->post_type === 'gv_message' && in_array( $screen->base, ['post','post-new'], true) ) {
            $base = plugin_dir_url(__FILE__);
            wp_enqueue_style('gv-forms-message-edit', $base.'assets/gv-forms-message-edit.css', [], GV_Contact_Form_Pro::VERSION);

            wp_add_inline_script('jquery-core', "
              jQuery(function($){
                $(document).on('click', '.copy-to-clipboard', function(e){
                  e.preventDefault();
                  const t = $(this).data('target');
                  const el = $(t).get(0);
                  if(!el) return;
                  el.select(); document.execCommand('copy');
                  $(this).text('Copied').delay(1200).queue(function(n){ $(this).text('Copy'); n(); });
                });
              });
            ");
        }

        // Builder screen
        if ( $hook === 'toplevel_page_gvforms' || $hook === 'gvforms_page_gvforms' ) {
            $base = plugin_dir_url(__FILE__);

            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style('gv-forms-admin', $base.'assets/gv-forms-admin.css', [], GV_Contact_Form_Pro::VERSION);

            // Load frontend CSS contents for iframe injection
            $frontend_css = '';
            $css_file = plugin_dir_path(__FILE__) . 'assets/gv-forms.css';
            if ( file_exists($css_file) ) {
                $frontend_css = file_get_contents($css_file);
            }

            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script('gv-forms-admin', $base.'assets/gv-forms-admin.js',
                ['jquery','jquery-ui-sortable','wp-color-picker'], GV_Contact_Form_Pro::VERSION, true);

            wp_localize_script('gv-forms-admin','gvFormsAdmin',[
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('gv_forms_admin'),
                'fields'     => $this->get_fields(),
                'title'      => [
                    'text'   => get_option(self::OPT_TITLE_TEXT,''),
                    'align'  => get_option(self::OPT_TITLE_ALIGN,'left'),
                    'color'  => get_option(self::OPT_TITLE_COLOR,'#ffffff'),
                ],
                // provide CSS for the iframe
                'previewCss' => $frontend_css,
                
            ]);
            wp_add_inline_script(
  'gv-forms-admin',
  <<<'JS'
jQuery(function($){
  $(document).on('click', '.gv-copy', async function(e){
    e.preventDefault();
    const $btn = $(this);
    const sel  = $btn.data('target');
    const el   = sel ? $(sel).get(0) : null;
    if (!el) return;

    const text = (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')
      ? el.value
      : ($(el).text() || $(el).val() || '');

    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
      } else {
        // Fallback for older browsers
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
        document.body.appendChild(ta); ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      $btn.text('Copied');
      setTimeout(() => $btn.text('Copy'), 1200);
    } catch (err) {
      console && console.warn && console.warn('Copy failed', err);
    }
  });
});
JS
);
            
        }

        // Messages list table styling
        if ( $screen && $screen->id === 'edit-gv_message' ) {
            $base = plugin_dir_url(__FILE__);
            wp_enqueue_style('gv-forms-messages', $base.'assets/gv-forms-messages.css', [], GV_Contact_Form_Pro::VERSION);
        }
    }

    /**
     * Admin-only live preview markup (no submit, no handlers)
     * FIX: This function is now backward-compatible.
     */
    public function render_preview_markup(...$args){
        ob_start();

        $params = [];

        if (isset($args[0]) && is_array($args[0]) && isset($args[0]['fields'])) {
            $params = $args[0]; // New AJAX way
        } else {
            // Support the old call signature
            $params['fields']      = $args[0] ?? [];
            $params['title_text']  = $args[1] ?? '';
            $params['title_align'] = $args[2] ?? 'left';
            $params['title_color'] = $args[3] ?? '#ffffff';
            $params['label_color'] = $args[4] ?? '#ffffff';
        }

        // Extract params with defaults from a central source
        $defaults = [
            'fields'        => $this->get_fields(),
            'title_text'    => get_option(self::OPT_TITLE_TEXT, ''),
            'title_align'   => get_option(self::OPT_TITLE_ALIGN, 'left'),
            'title_color'   => get_option(self::OPT_TITLE_COLOR, '#ffffff'),
            'label_color'   => get_option(self::OPT_LABEL_COLOR, '#ffffff'),
            'bg_color'      => get_option(self::OPT_BG_COLOR, '#000'),
            'glass_blur'    => get_option(self::OPT_GLASS_BLUR, '20'),
            'border_width'  => get_option(self::OPT_BORDER_WIDTH, '1'),
            'border_style'  => get_option(self::OPT_BORDER_STYLE, 'solid'),
            'border_color'  => get_option(self::OPT_BORDER_COLOR, '#22293d'),
            // New 3.0 defaults
            'padding'       => get_option(self::OPT_PADDING, '2.5'),
            'border_radius' => get_option(self::OPT_BORDER_RADIUS, '16'),
            'shadow'        => get_option(self::OPT_SHADOW, '1'),
        ];
        
        $p = wp_parse_args($params, $defaults);

        $fields      = is_array($p['fields']) ? $p['fields'] : $defaults['fields'];
        $title_text  = (string) $p['title_text'];
        $title_align = (string) $p['title_align'];
        $title_color = (string) $p['title_color'];
        $label_color = (string) $p['label_color'];
        $bg          = (string) $p['bg_color'];
        $blur        = (int) $p['glass_blur'];
        $border_css  = (float)$p['border_width'] > 0
            ? sprintf('%spx %s %s', esc_attr($p['border_width']), esc_attr($p['border_style']), esc_attr($p['border_color']))
            : 'none';
        
        // New 3.0 styles
        $padding_rem = (float)$p['padding'];
        $radius_px   = (int)$p['border_radius'];
        $shadow_css  = !empty($p['shadow']) ? '0 24px 166px 0 rgb(0 20 60 / 55%), 0 2px 4px 0 rgba(0,0,0,.05)' : 'none';


        /* pull from main plugin options so preview matches frontend */
        $opts      = get_option(GV_Contact_Form_Pro::OPT_KEY, []);
        $gdpr_on   = !empty($opts['gdpr']);
        $gdpr_text = $opts['gdpr_text']   ?? 'I consent to having this site store my submitted information.';
        $submit_tx = $opts['submit_text'] ?? 'Send';

        echo '<style>
  .gv-form label{color:'.$label_color.'!important}
  .gv-form-title{margin:0 0 1rem;font-size:clamp(1.25rem,2.5vw,1.75rem);line-height:1.2;font-weight:600}
  .gv-form {
    background:' . esc_attr($bg) . ' !important;
    backdrop-filter:blur(' . intval($blur) . 'px) saturate(1.2) !important;
    -webkit-backdrop-filter:blur(' . intval($blur) . 'px) saturate(1.2) !important;
    border:' . $border_css . ';
    padding: ' . $padding_rem . 'rem;
    border-radius: ' . $radius_px . 'px;
    box-shadow: ' . $shadow_css . ';
  }
  .gv-form .gv-consent{display:flex;flex-direction:row;align-items:flex-start;gap:.75rem;margin:.35rem 0 0}
  .gv-form .gv-consent input[type=checkbox]{margin-top:2px;flex:0 0 22px}
</style>';
?>


<form class="gv-form" data-admin-preview="1" onsubmit="return false">
  <?php if ($title_text !== ''): ?>
    <h3 class="gv-title" style="margin:0 0 6px 0;font-weight:700;color:<?php echo esc_attr($title_color); ?>;text-align:<?php echo esc_attr($title_align); ?>;">
      <?php echo esc_html($title_text); ?>
    </h3>
  <?php endif; ?>

  <?php foreach ($fields as $f):
      $label = $f['label'] ?? ucfirst($f['slug']);
      $slug  = sanitize_key($f['slug'] ?? 'field');
      $type  = in_array(($f['type'] ?? 'text'), ['text','email','textarea'], true) ? $f['type'] : 'text';
      $req   = !empty($f['required']);
      $ph    = $f['placeholder'] ?? '';
      $id    = 'f_'.$slug;
  ?>
    <div class="gv-field">
      <label for="<?php echo esc_attr($id); ?>" style="display:block;margin-bottom:4px;font-weight:600;color:<?php echo esc_attr($label_color); ?>">
        <?php echo esc_html($label); ?><?php if ($req): ?> <span aria-hidden="true">*</span><?php endif; ?>
      </label>
      <?php if ($type === 'textarea'): ?>
        <textarea id="<?php echo esc_attr($id); ?>" placeholder="<?php echo esc_attr($ph); ?>" rows="4"></textarea>
      <?php else: ?>
        <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($type); ?>" placeholder="<?php echo esc_attr($ph); ?>">
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php if ($gdpr_on): ?>
  <label class="gv-consent">
    <input type="checkbox" disabled>
    <span><?php echo esc_html($gdpr_text); ?></span>
  </label>
<?php endif; ?>

<div class="gv-submit">
  <button type="button" disabled><?php echo esc_html($submit_tx . ' (preview)'); ?></button>
</div>




</form>

<?php
        return ob_get_clean();
    }

    /* -----------------------------
     * Defaults
     * --------------------------- */
    public function default_fields() {
        return [
            [ 'label' => 'Name',    'slug' => 'name',    'type' => 'text',     'required' => 1, 'placeholder' => 'Your name' ],
            [ 'label' => 'Company', 'slug' => 'company', 'type' => 'text',     'required' => 0, 'placeholder' => 'Company (optional)' ],
            [ 'label' => 'Email',   'slug' => 'email',   'type' => 'email',    'required' => 1, 'placeholder' => 'you@example.com' ],
            [ 'label' => 'Message', 'slug' => 'message', 'type' => 'textarea', 'required' => 1, 'placeholder' => 'How can we help?' ],
        ];
    }

    /* -----------------------------
     * Read fields (for front-end & CSV)
     * --------------------------- */
    public function get_fields() {
        $stored = get_option( self::OPT_FIELDS, [] );
        if ( empty( $stored ) || ! is_array( $stored ) ) return $this->default_fields();
        return $stored;
    }

    /* -----------------------------
     * Builder screen
     * --------------------------- */
    public function page_builder() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die('Permission denied');

        ?>
        <div class="wrap gv-wrap gv-compact gv-forms-admin gv-split">
          <div class="gv-head">
            <h1>GV Forms — Builder</h1>
            <span class="gv-badge">Forms</span>
          </div>

          <div class="gv-split-grid">
            <div class="gv-editor">
              <div class="gv-card" style="margin-bottom:12px">
                <h2>Global styling</h2>

                <div class="gv-row">
                  <label for="gv-label-color" class="gv-lbl">Label color</label>
                  <input id="gv-label-color" type="text" value="<?php echo esc_attr(get_option(self::OPT_LABEL_COLOR, '#ffffff')); ?>">
                </div>

                <div class="gv-row">
                  <label for="gv-bg-color" class="gv-lbl">Background</label>
                  <input id="gv-bg-color" type="text" value="<?php echo esc_attr(get_option(self::OPT_BG_COLOR, '#000000')); ?>">
                </div>
                
                <div class="gv-row">
                  <label for="gv-glass-blur" class="gv-lbl">Glassmorphism</label>
                  <input id="gv-glass-blur" type="range" min="0" max="30" step="1"
                         value="<?php echo esc_attr(get_option(self::OPT_GLASS_BLUR, '20')); ?>">
                  <span id="gv-glass-blur-val"><?php echo esc_html(get_option(self::OPT_GLASS_BLUR, '20')); ?>px</span>
                </div>
                
                <div class="gv-row">
                  <label for="gv-padding" class="gv-lbl">Padding</label>
                  <input id="gv-padding" type="number" min="0" max="5" step="0.1"
                         value="<?php echo esc_attr(get_option(self::OPT_PADDING, '2.5')); ?>" style="width: 70px;" title="Padding (rem)">
                  <span class="gv-suffix">rem</span>
                </div>
                
                <div class="gv-row">
                  <label for="gv-border-radius" class="gv-lbl">Border Radius</label>
                  <input id="gv-border-radius" type="number" min="0" max="40" step="1"
                         value="<?php echo esc_attr(get_option(self::OPT_BORDER_RADIUS, '16')); ?>" style="width: 70px;" title="Border Radius (px)">
                  <span class="gv-suffix">px</span>
                </div>

                <div class="gv-row" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <label for="gv-border-width" class="gv-lbl" style="flex: 0 0 auto; margin-bottom:0;">Border</label>
                  <input id="gv-border-width" type="number" min="0" max="10" step="0.5"
                         value="<?php echo esc_attr(get_option(self::OPT_BORDER_WIDTH, '1')); ?>" style="width: 70px;" title="Border Width (px)">
                  <select id="gv-border-style" style="width: 90px;" title="Border Style">
                    <?php
                    $current_style = get_option(self::OPT_BORDER_STYLE, 'solid');
                    foreach (['solid', 'dashed', 'dotted', 'double'] as $style): ?>
                      <option value="<?php echo esc_attr($style); ?>" <?php selected($current_style, $style); ?>>
                        <?php echo esc_html(ucfirst($style)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <input id="gv-border-color" type="text"
                         value="<?php echo esc_attr(get_option(self::OPT_BORDER_COLOR, '#22293d')); ?>" title="Border Color">
                </div>
                
                <div class="gv-row">
                  <label for="gv-shadow-toggle" class="gv-lbl">3D Shadow</label>
                  <label><input id="gv-shadow-toggle" type="checkbox" value="1" <?php checked(get_option(self::OPT_SHADOW, '1')); ?>> Enable Shadow</label>
                </div>

                <div class="gv-form-title-controls">
                  <input id="gv-title-text" type="text" placeholder="Form title (optional)"
                         value="<?php echo esc_attr(get_option(self::OPT_TITLE_TEXT, '')); ?>" class="regular-text">
                  <div class="button-group" role="group" aria-label="Alignment">
                    <button type="button" class="button gv-align <?php echo get_option(self::OPT_TITLE_ALIGN,'left')==='left'?'button-primary':''; ?>"  data-align="left">L</button>
                    <button type="button" class="button gv-align <?php echo get_option(self::OPT_TITLE_ALIGN,'center')==='center'?'button-primary':''; ?>" data-align="center">C</button>
                    <button type="button" class="button gv-align <?php echo get_option(self::OPT_TITLE_ALIGN,'right')==='right'?'button-primary':''; ?>" data-align="right">R</button>
                  </div>
                  <input id="gv-title-align" type="hidden" value="<?php echo esc_attr(get_option(self::OPT_TITLE_ALIGN,'left')); ?>">
                  <input id="gv-title-color" type="text" value="<?php echo esc_attr(get_option(self::OPT_TITLE_COLOR,'#ffffff')); ?>">
                </div>
              </div>

              <div class="gv-card">
                <h2>Fields</h2>
                <div id="gv-field-list" class="gv-field-list"></div>
                <p class="submit">
                  <button id="gv-add"  type="button" class="button">Add field</button>
                  <button id="gv-save" type="button" class="button button-primary">Save fields</button>
                  <span class="spinner" style="float:none;margin:0 8px;"></span>
                  <span id="gv-save-msg" style="vertical-align:middle;"></span>
                </p>
              </div>
    <?php
      $php_include_snippet = "<?php echo do_shortcode('[gv_contact_form]'); ?>";
      $preview_url = esc_url( add_query_arg('gv-preview','1', home_url('/')) );
    ?>
    <div class="gv-card gv-card-embed">
      <h2>Embed / How to use</h2>
      <p class="description">Add the form with a Gutenberg block or the shortcode.</p>

      <div class="gv-code-group">
        <h3>Gutenberg block</h3>
        <p>Add block: <strong>GV Contact Form</strong> (Widgets). Shows a live preview.</p>

        <h3>Shortcode</h3>
        <div class="gv-code-row">
          <input id="gv-sc" class="gv-code" type="text" readonly value="<?php echo esc_attr('[gv_contact_form]'); ?>">
          <button class="button button-small gv-copy" data-target="#gv-sc">Copy</button>
        </div>

        <h3>PHP include</h3>
        <div class="gv-code-row">
          <input id="gv-php" class="gv-code" type="text" readonly value="<?php echo esc_attr($php_include_snippet); ?>">
          <button class="button button-small gv-copy" data-target="#gv-php">Copy</button>
        </div>

        <div class="gv-code-actions">
          <a class="button" target="_blank" rel="noopener" href="<?php echo $preview_url; ?>">Open preview</a>
        </div>
      </div>
    </div>

            </div>

            

    <aside class="gv-preview">
  <div class="gv-preview-sticky">



    <div class="gv-card">
      <h2>Live preview</h2>
      <iframe id="gv-live-frame" style="width:100%; min-height:550px; border:none;" scrolling="no"></iframe>
    </div>
    <p class="description" style="margin-top:8px">Preview updates as you type, sort, toggle required, or change colors.</p>
  </div>
</aside>


          </div>
        </div>
        
        <?php
    }
    
    
    /* -----------------------------
     * AJAX: save fields + colors + title
     * --------------------------- */
    public function ajax_save_fields() {
        if ( ! current_user_can('manage_options') )
            wp_send_json_error('forbidden',403);

        if ( empty($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'],'gv_forms_admin') )
            wp_send_json_error('bad nonce',403);

        // Decode fields
        $raw = wp_unslash($_POST['fields'] ?? '[]');
        $arr = json_decode($raw,true);
        if ( !is_array($arr) ) $arr = [];

        $clean=[];
        foreach($arr as $f){
            $label = sanitize_text_field($f['label'] ?? '');
            $slug  = sanitize_key($f['slug'] ?? '');
            if ($slug==='') $slug=sanitize_key($label);
            $type  = in_array($f['type'] ?? 'text',['text','email','textarea'],true)?$f['type']:'text';
            $req   = !empty($f['required'])?1:0;
            $ph    = sanitize_text_field($f['placeholder'] ?? '');
            if ($slug==='email') $type='email';
            $clean[]=['label'=>$label?:ucfirst($slug),'slug'=>$slug,'type'=>$type,'required'=>$req,'placeholder'=>$ph];
        }

        // Color + visual options
        $label_color = sanitize_hex_color($_POST['label_color'] ?? '#ffffff') ?: '#ffffff';
        $title_text  = sanitize_text_field($_POST['title_text'] ?? '');
        $title_align = in_array($_POST['title_align'] ?? 'left',['left','center','right'],true)?$_POST['title_align']:'left';
        $title_color = sanitize_hex_color($_POST['title_color'] ?? '#ffffff') ?: '#ffffff';
        $bg_color    = sanitize_hex_color($_POST['bg_color'] ?? '#000000') ?: '#000000';
        $glass_blur  = max(0,min(30,intval($_POST['glass_blur'] ?? 20)));
        
        $border_width = max(0, min(10, (float)($_POST['border_width'] ?? 1)));
        $border_style = sanitize_key($_POST['border_style'] ?? 'solid');
        if (!in_array($border_style, ['solid', 'dashed', 'dotted', 'double'])) {
            $border_style = 'solid';
        }
        $border_color = sanitize_hex_color($_POST['border_color'] ?? '#22293d') ?: '#22293d';

        // New 3.0 options
        $padding       = max(0, min(5, (float)($_POST['padding'] ?? 2.5)));
        $border_radius = max(0, min(40, (int)($_POST['border_radius'] ?? 16)));
        $shadow        = !empty($_POST['shadow']) ? '1' : '0';

        // Preview only → render, do not persist
        if ( isset($_POST['preview_only']) ) {
            
            $preview_params = [
                'fields'        => $clean,
                'title_text'    => $title_text,
                'title_align'   => $title_align,
                'title_color'   => $title_color,
                'label_color'   => $label_color,
                'bg_color'      => $bg_color,
                'glass_blur'    => $glass_blur,
                'border_width'  => $border_width,
                'border_style'  => $border_style,
                'border_color'  => $border_color,
                'padding'       => $padding,
                'border_radius' => $border_radius,
                'shadow'        => $shadow,
            ];
            
            ob_start();
            echo $this->render_preview_markup($preview_params); // Pass all params
            $html = ob_get_clean();
            wp_send_json_success(['html'=>$html]);
        }

        // Persist
        update_option(self::OPT_FIELDS,       $clean,        false);
        update_option(self::OPT_LABEL_COLOR,  $label_color,  false);
        update_option(self::OPT_TITLE_TEXT,   $title_text,   false);
        update_option(self::OPT_TITLE_ALIGN,  $title_align,  false);
        update_option(self::OPT_TITLE_COLOR,  $title_color,  false);
        update_option(self::OPT_BG_COLOR,     $bg_color,     false);
        update_option(self::OPT_GLASS_BLUR,   $glass_blur,   false);
        update_option(self::OPT_BORDER_WIDTH, $border_width, false);
        update_option(self::OPT_BORDER_STYLE, $border_style, false);
        update_option(self::OPT_BORDER_COLOR, $border_color, false);
        update_option(self::OPT_PADDING,      $padding,      false);
        update_option(self::OPT_BORDER_RADIUS,$border_radius, false);
        update_option(self::OPT_SHADOW,       $shadow,       false);

        wp_send_json_success(['ok'=>1]);
    }
}