<?php
/*
Plugin Name: GV Contact Form Pro
Description: AJAX contact form with honeypot, reCAPTCHA v3, GDPR-consent, rate-limit, admin options, CSV-export, private CPT storage.
Version: 0.7.3
Author: Gardevault
Author URI: https://gardevault.com
Plugin URI: https://gardevault.com/plugins/gv-simple-2fa
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/*───────────────────────────*/
/* Activation – wizard flag  */
/*───────────────────────────*/
register_activation_hook( __FILE__, function () {
    set_transient( '_gv_forms_activation', 1, 30 );   // 30 s → redirect once
} );

/*───────────────────────────*/
/* Main class                */
/*───────────────────────────*/
class GV_Contact_Form_Pro {

    /* === CONSTANTS === */
    const VERSION     = '0.7.3';
    const CPT         = 'gv_message';
    const NONCE_KEY   = 'gv_forms';
    const ACTION      = 'gv_submit_contact';
    const OPT_KEY     = 'gv_forms_opts';
    const CSV_ACTION  = 'gv_forms_export';

    /* === DEFAULT OPTIONS === */
private $defaults = array(
  'admin_email' => '',
  'rate_limit'  => 5,
  'csv_delim'   => ',',
  'auto_reply'  => 1,
  'gdpr'        => 1,
  'recaptcha_enabled' => 1,
  'recaptcha_key'     => '',
  'recaptcha_secret'  => '',
  'gdpr_text'   => 'I consent to having this site store my submitted information.',
  'submit_text' => 'Send',
);

    /* === BOOT === */
    public function __construct() {

        /* Core */
        add_action( 'init',                 array( $this, 'register_cpt' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
      add_action('template_redirect', function () {
  if (isset($_GET['gv-preview'])) {
    // Prevent toolbar and full theme load
    show_admin_bar(false);
    status_header(200);
    nocache_headers();

    // Minimal environment: load only the needed CSS + form
    $dir = plugin_dir_url(__FILE__);
    wp_enqueue_style('gv-forms', $dir . 'assets/gv-forms.css', [], GV_Contact_Form_Pro::VERSION);
    wp_head();

    echo '<body style="
      margin:0;
      background:#000;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:560px;
      padding:40px;
      overflow:hidden;
    ">';
    echo '<div style="max-width:580px;width:100%;transform:scale(0.92);transform-origin:top center;">';
    echo do_shortcode('[gv_contact_form]');
    echo '</div>';
    echo '</body>';

    wp_footer();
    exit;
  }
});



        /* Shortcode & AJAX */
        add_shortcode( 'gv_contact_form',                array( $this, 'form' ) );
        add_shortcode('gv_contact_form_live_preview', function() {
    $admin = new GV_Contact_Form_Admin();
    // This preview shortcode is only for the Gutenberg block, which needs the old signature
    // The main live preview is handled by render_preview_markup() via AJAX
    $fields = $admin->get_fields();
    $title_text  = get_option(GV_Contact_Form_Admin::OPT_TITLE_TEXT, '');
    $title_align = get_option(GV_Contact_Form_Admin::OPT_TITLE_ALIGN, 'left');
    $title_color = get_option(GV_Contact_Form_Admin::OPT_TITLE_COLOR, '#ffffff');
    $label_color = get_option(GV_Contact_Form_Admin::OPT_LABEL_COLOR, '#ffffff');
    return $admin->render_preview_markup($fields, $title_text, $title_align, $title_color, $label_color);
});

        add_action( 'wp_ajax_'        . self::ACTION, array( $this, 'handle' ) );
        add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );

        /* Admin list UI */
        add_filter( 'manage_edit-' . self::CPT . '_columns',              array( $this, 'cols' ) );
        add_action( 'manage_'      . self::CPT . '_posts_custom_column', array( $this, 'col_content' ), 10, 2 );
        add_action( 'add_meta_boxes',   array( $this, 'metabox' ) );
        add_action( 'admin_head',       array( $this, 'remove_publish_box' ) );
        add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

        /* Messages page tweaks + CSV button (footer ⇒ jQuery ready) */
        add_action( 'admin_footer-edit.php', array( $this, 'customize_messages_page' ) );

        /* Export & Settings */
        add_action( 'admin_post_' . self::CSV_ACTION, array( $this, 'export_csv' ) );

        add_action( 'admin_menu', array( $this, 'settings_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        /* Duplicate settings under GV Forms top-level */
        add_action( 'admin_menu', function () {
            add_submenu_page(
                'gvforms', 'Settings', 'Settings',
                'manage_options', 'gv-contact-settings',
                array( $this, 'settings_page' )
            );
        }, 11 );

add_action('gv_core_register_module', function () {
  if (!function_exists('gv_core_register_module')) return;
  gv_core_register_module([
    'slug'        => 'gvforms',
    'name'        => 'GV Contact Form Pro',
    'version'     => GV_Contact_Form_Pro::VERSION,
    'settings_url'=> admin_url('admin.php?page=gvforms'), // Builder
    'panel_cb'    => function () {
        $csv_url = wp_nonce_url(
      admin_url('admin-post.php?action=gv_forms_export'), // Base URL
      'gv_forms_export' // The nonce action key
  );
      echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=gvforms')).'">Open Builder</a> ';
      echo '<a class="button" href="'.esc_url(admin_url('options-general.php?page=gv-contact-settings')).'">Settings</a> ';
  echo '<a class="button" href="'.esc_url($csv_url).'">Quick CSV Export</a>';
    },
    'cap'         => 'manage_options',
  ]);
});



        /* Mail headers: DMARC-safe defaults (can be disabled/overridden) */
        if ( ! defined( 'GV_FORMS_DISABLE_FROM_FILTERS' ) || ! GV_FORMS_DISABLE_FROM_FILTERS ) {
            add_filter( 'wp_mail_from',      array( $this, 'filter_mail_from' ) );
            add_filter( 'wp_mail_from_name', array( $this, 'filter_mail_from_name' ) );
            add_action( 'phpmailer_init',    array( $this, 'filter_phpmailer_sender' ) );
        }
    }

    /* === OPTION HELPER === */
    private function opt( $key ) {
        $opts = get_option( self::OPT_KEY, array() );
        $opts = wp_parse_args( $opts, $this->defaults );
        return $opts[ $key ] ?? null;
    }

    /* === MAIL HELPERS (dynamic domain / DMARC alignment) === */
    private function mail_domain(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host ) $host = wp_parse_url( site_url(), PHP_URL_HOST );
        if ( ! $host ) $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        $host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
        if ( function_exists( 'idn_to_ascii' ) ) {
            $ascii = idn_to_ascii( $host, 0 );
            if ( $ascii ) $host = $ascii;
        }
        $host = preg_replace( '/[^a-z0-9\.\-]/', '', $host );

        $host = apply_filters( 'gv_forms_mail_domain', $host );

        return $host ?: 'localhost';
    }

    private function make_address( string $local ): string {
        $local = preg_replace( '/[^a-z0-9._%+\-]/i', '', $local );
        $email = $local . '@' . $this->mail_domain();
        return apply_filters( 'gv_forms_make_address', $email, $local );
    }

    public function filter_mail_from( $from ) {
        $addr = $this->make_address( 'wordpress' ); // or 'notifications'
        return apply_filters( 'gv_forms_from_email', $addr, $from );
    }

    public function filter_mail_from_name( $name ) {
        $nm = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        return apply_filters( 'gv_forms_from_name', $nm, $name );
    }

    public function filter_phpmailer_sender( $phpmailer ) {
        $phpmailer->Sender = $this->make_address( 'bounce' ); // Return-Path/envelope sender
    }

    /* === CPT === */
    public function register_cpt() {
        register_post_type( self::CPT, array(
            'label'             => 'Messages',
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => 'gvforms',
            'menu_icon'         => 'dashicons-email',
            'supports'          => array( 'title' ),
            'capability_type'   => 'post',
            'capabilities'      => array( 'create_posts' => false ),
            'map_meta_cap'      => true,
        ) );
    }

    

    /* === FRONT-END ASSETS === */
    public function assets() {
        if ( ! is_singular() || ! has_shortcode( get_post()->post_content ?? '', 'gv_contact_form' ) ) return;

        $dir = plugin_dir_url( __FILE__ );
        wp_enqueue_style( 'gv-forms', $dir . 'assets/gv-forms.css', array(), self::VERSION );
        add_filter( 'style_loader_tag', [ $this, 'gv_forms_non_blocking_css' ], 10, 4 );

        wp_register_script( 'gv-forms', $dir . 'assets/gv-forms.js', array(), self::VERSION, true );
        wp_localize_script( 'gv-forms', 'gvForms', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            // Only pass key if enabled + present. JS will lazy-load Google on interaction.
            'recaptchaKey' => ( $this->opt( 'recaptcha_enabled' ) && $this->opt( 'recaptcha_key' ) ) ? $this->opt( 'recaptcha_key' ) : '',
        ) );
        wp_enqueue_script( 'gv-forms' );

        // IMPORTANT: Do NOT enqueue Google's reCAPTCHA script here.
        // We rely on gv-forms.js to inject it after user interaction (no PSI impact).

        
    }

    public function gv_forms_non_blocking_css( $html, $handle, $href, $media ) {
        if ( $handle !== 'gv-forms' ) return $html;

        $href  = esc_url( $href );
        $media = $media ? esc_attr( $media ) : 'all';

        return "<link rel='stylesheet' href='{$href}' media='print' onload=\"this.onload=null;this.media='all'\">"
             . "<noscript><link rel='stylesheet' href='{$href}' media='{$media}'></noscript>";
    }

    /* === ADMIN ASSETS === */
    public function admin_assets( $hook ) {
        if ( $hook === 'settings_page_gv-contact-settings' ) {
            wp_enqueue_style( 'gv-admin', plugin_dir_url( __FILE__ ) . 'assets/gv-forms-admin.css', [], self::VERSION );
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
        }
    }

    /* === SHORTCODE RENDER === */
public function form() {
    $fields          = class_exists('GV_Contact_Form_Admin')
                        ? (new GV_Contact_Form_Admin)->get_fields()
                        : array();

    // --- visual from Builder ---
    $bg           = get_option(GV_Contact_Form_Admin::OPT_BG_COLOR, '#000000');
    $blur         = (int) get_option(GV_Contact_Form_Admin::OPT_GLASS_BLUR, 20);
    $border_width = get_option(GV_Contact_Form_Admin::OPT_BORDER_WIDTH, '1');
    $border_style = get_option(GV_Contact_Form_Admin::OPT_BORDER_STYLE, 'solid');
    $border_color = get_option(GV_Contact_Form_Admin::OPT_BORDER_COLOR, '#22293d');
    
    // New 3.0 options
    $padding = (float) get_option(GV_Contact_Form_Admin::OPT_PADDING, '2.5');
    $radius  = (int) get_option(GV_Contact_Form_Admin::OPT_BORDER_RADIUS, '16');
    $shadow_on = get_option(GV_Contact_Form_Admin::OPT_SHADOW, '1') === '1';

    $border_css   = (float)$border_width > 0
        ? sprintf('%spx %s %s', esc_attr($border_width), esc_attr($border_style), esc_attr($border_color))
        : 'none';
    $shadow_css   = $shadow_on 
        ? '0 24px 166px 0 rgb(0 20 60 / 55%), 0 2px 4px 0 rgba(0,0,0,.05)' 
        : 'none';

    // --- labels + i18n from settings ---
    $label_colour  = esc_attr( get_option('gv_forms_label_color', '#ffffff') );
    $recaptcha_key = ($this->opt('recaptcha_enabled') && $this->opt('recaptcha_key')) ? $this->opt('recaptcha_key') : '';

    $title_text  = get_option('gv_forms_title_text',  '');
    $title_align = get_option('gv_forms_title_align', 'left');
    $title_color = get_option('gv_forms_title_color', '#ffffff');

    $gdpr_on   = (int) $this->opt('gdpr') === 1;
    $gdpr_text = $this->opt('gdpr_text') ?: 'I consent to having this site store my submitted information.';
    $submit_tx = $this->opt('submit_text') ?: 'Send';

    ob_start(); ?>
    <style>
      .gv-form label{color:<?php echo $label_colour; ?>!important}
      .gv-form-title{margin:0 0 1rem;font-size:clamp(1.25rem,2.5vw,1.75rem);line-height:1.2;font-weight:600}
      .gv-form{
          background:<?php echo esc_attr($bg); ?>;
          border:<?php echo $border_css; ?>;
          backdrop-filter:blur(<?php echo (int)$blur; ?>px) saturate(1.2);
          -webkit-backdrop-filter:blur(<?php echo (int)$blur; ?>px) saturate(1.2);
          padding: <?php echo $padding; ?>rem;
          border-radius: <?php echo $radius; ?>px;
          box-shadow: <?php echo $shadow_css; ?>;
      }
      .gv-form .gv-consent{display:flex;flex-direction:row;align-items:flex-start;gap:.75rem;margin:.35rem 0 0}
      .gv-form .gv-consent input[type=checkbox]{margin-top:2px;flex:0 0 22px}
    </style>
    <?php if ($title_text) : ?>
      <h3 class="gv-form-title" style="text-align:<?php echo esc_attr($title_align); ?>;color:<?php echo esc_attr($title_color); ?>">
        <?php echo esc_html($title_text); ?>
      </h3>
    <?php endif; ?>

    <form class="gv-form" data-action="<?php echo esc_attr(self::ACTION); ?>" data-recaptcha-key="<?php echo esc_attr($recaptcha_key); ?>">
      <?php wp_nonce_field(self::NONCE_KEY, 'gv_nonce'); ?>
      <input type="text" name="hp" style="display:none" tabindex="-1" autocomplete="off">
      <?php if ($recaptcha_key): ?><input type="hidden" name="recaptcha_token" value=""><?php endif; ?>

      <?php foreach ($fields as $f):
            $slug = esc_attr($f['slug']); $lbl = esc_html($f['label']); $req = $f['required'] ? 'required' : '';
            $ph = esc_attr($f['placeholder'] ?? ''); ?>
        <?php if ($f['type'] === 'textarea'): ?>
          <label><?php echo $lbl; ?><textarea name="<?php echo $slug; ?>" <?php echo $req; ?> placeholder="<?php echo $ph; ?>"></textarea></label>
        <?php else: $type = in_array($f['type'], ['email','text'], true) ? $f['type'] : 'text'; ?>
          <label><?php echo $lbl; ?><input type="<?php echo $type; ?>" name="<?php echo $slug; ?>" <?php echo $req; ?> placeholder="<?php echo $ph; ?>"></label>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($gdpr_on): ?>
        <label class="gv-consent checkbox-container">
          <input type="checkbox" name="consent" value="1" required>
          <span class="label-text"><?php echo esc_html($gdpr_text); ?></span>
        </label>
      <?php endif; ?>

      <button type="submit"><?php echo esc_html($submit_tx); ?></button>
      <?php if ($recaptcha_key): ?><small class="gv-recap-note">Protected by reCAPTCHA</small><?php endif; ?>
      <div class="gv-resp"></div>
    </form>
    <?php return ob_get_clean();
}


    /* === REQUEST VERIFICATION === */
    private function verify() {
        if ( empty( $_POST['gv_nonce'] ) || ! wp_verify_nonce( $_POST['gv_nonce'], self::NONCE_KEY ) )
            wp_send_json_error( 'Invalid nonce', 403 );

        if ( ! empty( $_POST['hp'] ) )
            wp_send_json_success( 'OK' );  // bot caught

        if ( $this->opt( 'gdpr' ) && empty( $_POST['consent'] ) )
            wp_send_json_error( 'Consent required', 400 );

        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua  = substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 60 );
        $key = 'gv_rate_' . md5( $ip . $ua );
        $cnt = (int) get_transient( $key );
        if ( $cnt >= $this->opt( 'rate_limit' ) )
            wp_send_json_error( 'Too many messages, try later.', 429 );
        set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );

        // Only verify if enabled + secret present.
        if ( $this->opt( 'recaptcha_enabled' ) && $this->opt( 'recaptcha_secret' ) ) {
            $token = sanitize_text_field( $_POST['recaptcha_token'] ?? '' );

            $resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
                'body'    => array(
                    'secret'   => $this->opt( 'recaptcha_secret' ),
                    'response' => $token,
                    'remoteip' => $ip,
                ),
                'timeout' => 5,
            ) );
            if ( is_wp_error( $resp ) )
                wp_send_json_error( 'reCAPTCHA request failed', 403 );

            $json = json_decode( wp_remote_retrieve_body( $resp ) );
            if ( empty( $json->success )
                 || ( isset( $json->score )  && floatval( $json->score ) < 0.3 )
                 || ( isset( $json->action ) && $json->action !== 'contact' ) )
                wp_send_json_error( 'reCAPTCHA failed', 403 );
        }
    }

    /* === AJAX HANDLER === */
    public function handle() {
        $this->verify();

        $name  = sanitize_text_field    ( $_POST['name']    ?? '' );
        $email = sanitize_email         ( $_POST['email']   ?? '' );
        $msg   = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( ! is_email( $email ) )
            wp_send_json_error( 'Invalid email', 400 );

        /* Store as private CPT */
        $meta = [ 'email' => $email ];
        foreach ( $_POST as $k => $v ) {
            if ( in_array( $k, [ 'hp', 'consent', 'recaptcha_token', 'gv_nonce' ], true ) ) continue;
            if ( is_array( $v ) ) continue;
            $meta[ sanitize_key( $k ) ] = sanitize_text_field( $v );
        }

        wp_insert_post( array(
            'post_type'   => self::CPT,
            'post_title'  => $name . ' – ' . current_time( 'mysql' ),
            'post_content'=> $msg,
            'post_status' => 'private',
            'meta_input'  => $meta,
        ) );

        /* Notify admin */
        $to   = $this->opt( 'admin_email' ) ?: get_option( 'admin_email' );
        $body = "Name: $name\nEmail: $email\nMessage:\n$msg";
        $headers = array(
            'Reply-To: ' . $email,
            'Content-Type: text/plain; charset=UTF-8',
        );
        wp_mail( $to, 'New Contact Message', $body, $headers );

        /* Auto-reply */
        if ( $this->opt( 'auto_reply' ) ) {
            wp_mail(
                $email,
                'We received your message',
                "Hi $name,\n\nThanks – we'll respond soon.\n\nGardeVault"
            );
        }

        wp_send_json_success( 'Thank you, we\'ll be in touch shortly.' );
    }

    /* === ADMIN LIST COLUMNS === */
    public function cols( $cols ) {
        return array(
            'cb'    => $cols['cb'],
            'title' => 'Name – Date',
            'email' => 'Email',
            'date'  => $cols['date'],
        );
    }
    public function col_content( $col, $post_id ) {
        if ( 'email' === $col )
            echo esc_html( get_post_meta( $post_id, 'email', true ) );
    }

    /* === METABOX === */
    public function metabox() {
        add_meta_box( 'gv_msg', 'Message', function ( $post ) {
            echo '<pre style="white-space:pre-wrap;font-family:inherit">'
                 . esc_html( $post->post_content ) . '</pre>';
        }, self::CPT, 'normal', 'high' );
    }
    public function remove_publish_box() {
        remove_meta_box( 'submitdiv', self::CPT, 'side' );
    }

    /* === CSV EXPORT === */
    private function csv_safe( $v ) {
        $v = preg_replace( "/\r|\n/", ' ', $v );
        if ( preg_match( '/^[=\+\-@]/', $v ) ) $v = "'" . $v;  // Excel formula-inj.
        return $v;
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ||
             ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', self::CSV_ACTION ) ) {
            wp_die( 'Permission denied' );
        }

      $delim = ( $this->opt('csv_delim') === ';' ) ? ';' : ',';


        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=gv-messages-' . date( 'Y-m-d' ) . '.csv' );
        echo "\xEF\xBB\xBF";

        $out = fopen( 'php://output', 'w' );

        $fields = class_exists( 'GV_Contact_Form_Admin' )
            ? ( new GV_Contact_Form_Admin )->get_fields()
            : [];

        $headers = [ 'Date' ];
        $slugs   = [];

        foreach ( $fields as $f ) {
            if ( $f['slug'] === 'message' ) continue;
            $headers[] = ucfirst( $f['label'] );
            $slugs[]   = $f['slug'];
        }
        $headers[] = 'Message';

        fputcsv( $out, $headers, $delim );

        $q = new WP_Query( [
            'post_type'      => self::CPT,
            'post_status'    => 'private',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ] );

        foreach ( $q->posts as $p ) {
            $title_parts = explode( ' – ', get_the_title( $p->ID ), 2 );
            $name = $title_parts[0];
            $date = get_the_date( 'Y-m-d H:i', $p->ID );

            $row = [ $date ];

            foreach ( $slugs as $slug ) {
                if ( $slug === 'name' ) {
                    $row[] = $this->csv_safe( $name );
                } else {
                    $row[] = $this->csv_safe( get_post_meta( $p->ID, $slug, true ) );
                }
            }

            $row[] = $this->csv_safe( $p->post_content );
            fputcsv( $out, $row, $delim );
        }

        fclose( $out );
        exit;
    }

    /* === LIST-PAGE TWEAKS (CSV btn & hide “Add New”) === */
    public function customize_messages_page() {
        $scr = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$scr || $scr->post_type !== self::CPT) return;

        $url = add_query_arg(
            [
                'action'   => self::CSV_ACTION,
                '_wpnonce' => wp_create_nonce( self::CSV_ACTION ),
            ],
            admin_url( 'admin-post.php' )
        );?>
        <style>
            .page-title-action { display:none !important; }
            #gv-export-btn     { display: inline-block!important; }
        </style>
        <script>
            jQuery(function ($) {
                $('<a>', {
                    id:    'gv-export-btn',
                    class: 'page-title-action',
                    text:  'Export to CSV',
                    href:  '<?php echo $url; ?>'
                }).insertAfter('.wp-heading-inline');
            });
        </script>
    <?php }

    /* Remove Quick-Edit & View links */
    public function remove_row_actions( $actions, $post ) {
        if ( $post->post_type === self::CPT ) {
            unset( $actions['inline hide-if-no-js'] );
            unset( $actions['view'] );
        }
        return $actions;
    }

    /* === SETTINGS PAGE === */
    public function settings_menu() {
        add_options_page(
            'GV Contact Settings', 'GV Contact',
            'manage_options', 'gv-contact-settings',
            array( $this, 'settings_page' )
        );
    }
    public function register_settings() {
        add_settings_field('gdpr_text', 'GDPR Text', array($this,'field_gdpr_text'), 'gv-contact-settings', 'gv_main');
add_settings_field('submit_text', 'Submit Button Text', array($this,'field_submit_text'), 'gv-contact-settings', 'gv_main');


        register_setting( 'gv_contact_grp', self::OPT_KEY );
        add_filter( 'pre_update_option_' . self::OPT_KEY, function ( $value, $old_value ) {
            if ( ! isset( $value['recaptcha_enabled'] ) ) {
                $value['recaptcha_enabled'] = 0;
            }
            return $value;
        }, 10, 2 ); 

        add_settings_section( 'gv_main', 'Main', null, 'gv-contact-settings' );

        add_settings_field( 'admin_email', 'Admin Email', array( $this, 'field_admin_email'  ), 'gv-contact-settings', 'gv_main' );
        add_settings_field( 'rate_limit',  'Rate Limit / hr', array( $this, 'field_rate' ),     'gv-contact-settings', 'gv_main' );
        add_settings_field( 'csv_delim',   'CSV Delimiter', array( $this, 'field_csv' ),        'gv-contact-settings', 'gv_main' );
        add_settings_field( 'auto_reply',  'Auto Reply',    array( $this, 'field_auto' ),       'gv-contact-settings', 'gv_main' );
        add_settings_field( 'gdpr',        'GDPR Checkbox', array( $this, 'field_gdpr' ),       'gv-contact-settings', 'gv_main' );
        add_settings_field( 'recaptcha_enabled', 'reCAPTCHA', array( $this, 'field_recaptcha_enable' ), 'gv-contact-settings', 'gv_main' );
        add_settings_field( 'recaptcha',   'reCAPTCHA Site/Secret', array( $this, 'field_recaptcha' ), 'gv-contact-settings', 'gv_main' );
    }

    public function field_gdpr_text() {
  printf('<input type="text" name="%s[gdpr_text]" value="%s" class="regular-text" placeholder="Consent text">',
         esc_attr(self::OPT_KEY), esc_attr($this->opt('gdpr_text')));
}
public function field_submit_text() {
  printf('<input type="text" name="%s[submit_text]" value="%s" class="regular-text" placeholder="Send">',
         esc_attr(self::OPT_KEY), esc_attr($this->opt('submit_text')));
}


    /* === FIELD RENDERERS === */
    public function field_admin_email() {
        printf( '<input type="email" name="%s[admin_email]" value="%s" class="regular-text">', esc_attr( self::OPT_KEY ), esc_attr( $this->opt( 'admin_email' ) ) );
    }
    public function field_rate() {
        printf( '<input type="number" name="%s[rate_limit]" value="%d" min="1" style="width:70px"> / IP+UA / hr', esc_attr( self::OPT_KEY ), intval( $this->opt( 'rate_limit' ) ) );
    }
    public function field_csv() {
        $sel = $this->opt( 'csv_delim' );
        printf( '<select name="%s[csv_delim]">', esc_attr( self::OPT_KEY ) );
        printf( '<option value="," %s>Comma</option>',     selected( $sel, ',', false ) );
        printf( '<option value=";" %s>Semicolon</option>', selected( $sel, ';', false ) );
        echo '</select>';
    }
    public function field_auto() {
        printf( '<label><input type="checkbox" name="%s[auto_reply]" value="1" %s> Send confirmation to user</label>', esc_attr( self::OPT_KEY ), checked( $this->opt( 'auto_reply' ), 1, false ) );
    }
    public function field_gdpr() {
        printf( '<label><input type="checkbox" name="%s[gdpr]" value="1" %s> Require GDPR consent</label>', esc_attr( self::OPT_KEY ), checked( $this->opt( 'gdpr' ), 1, false ) );
    }
    public function field_recaptcha_enable() {
        printf(
            '<label><input type="checkbox" name="%s[recaptcha_enabled]" value="1" %s> Enable Google reCAPTCHA</label>',
            esc_attr( self::OPT_KEY ),
            checked( $this->opt( 'recaptcha_enabled' ), 1, false )
        );
    }
    public function field_recaptcha() {
        printf( '<input type="text" name="%s[recaptcha_key]" value="%s" placeholder="Site key" class="regular-text"><br>', esc_attr( self::OPT_KEY ), esc_attr( $this->opt( 'recaptcha_key' ) ) );
        printf( '<input type="text" name="%s[recaptcha_secret]" value="%s" placeholder="Secret" class="regular-text">', esc_attr( self::OPT_KEY ), esc_attr( $this->opt( 'recaptcha_secret' ) ) );
    }

    public function settings_page() { ?>
        <div class="wrap">
            <h1>GV Contact – Settings</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'gv_contact_grp' );
                    do_settings_sections( 'gv-contact-settings' );
                    submit_button();
                ?>
            </form>
        </div>
    <?php }
} 



// END class
/*───────────────────────────*/
/* Gutenberg block: GV Contact Form */
/*───────────────────────────*/
add_action('init', function () {
    // Editor script
    wp_register_script(
        'gv-contact-form-block',
        plugins_url('assets/block-contact-form.js', __FILE__),
        ['wp-blocks','wp-element','wp-i18n','wp-components','wp-block-editor','wp-server-side-render'],
        GV_Contact_Form_Pro::VERSION,
        true
    );

    // Optional tiny editor-side CSS
    wp_register_style(
        'gv-contact-form-block',
        plugins_url('assets/block-contact-form.css', __FILE__),
        [],
        GV_Contact_Form_Pro::VERSION
    );

    register_block_type('gardevault/contact-form', [
        'api_version'     => 2,
        'editor_script'   => 'gv-contact-form-block',
        'editor_style'    => 'gv-contact-form-block',
        'render_callback' => function () {
            // Reuse the shortcode so one render path exists
            return do_shortcode('[gv_contact_form]');
        },
        'attributes'      => [],
        'title'           => 'GV Contact Form',
        'category'        => 'widgets',
        'icon'            => 'feedback',
        'supports'        => ['html' => false],
        'keywords'        => ['contact','form','gardevault','gv'],
    ]);
});

/*───────────────────────────*/
/* Boot plugin & admin class */
/*───────────────────────────*/
require_once plugin_dir_path( __FILE__ ) . 'gv-forms-admin.php';

new GV_Contact_Form_Pro();
if ( is_admin() ) new GV_Contact_Form_Admin();