<?php
/**
 * Plugin Name:       Table of Contents
 * Description:       Automatic table of contents from post headings: unique anchor IDs, a clean box before the first heading (or via [toc]), and a shortcode for manual placement.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wtoc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WTOC_OPTION', 'wtoc_settings' );

/** Default settings. Filterable via `wtoc_default_settings`. */
function wtoc_defaults() {
    return apply_filters( 'wtoc_default_settings', array(
        'levels'       => array( 'h2', 'h3' ), // Which headings become entries.
        'min_headings' => 3,                    // Skip the box on short posts.
        'title'        => 'Contents',
        'auto_insert'  => 1,                    // Insert before the first heading.
    ) );
}

function wtoc_settings() {
    $s = wp_parse_args( get_option( WTOC_OPTION, array() ), wtoc_defaults() );
    if ( ! is_array( $s['levels'] ) || empty( $s['levels'] ) ) {
        $s['levels'] = array( 'h2', 'h3' );
    }
    return $s;
}

/**
 * Extract headings from content and give each a unique slug anchor.
 * Returns array( $headings, $content_with_ids ). Headings keep their inner
 * HTML for display but the anchor slug is built from the plain text.
 */
function wtoc_process_content( $content ) {
    $settings = wtoc_settings();
    $levels   = array_map( 'strtolower', $settings['levels'] );
    $pattern  = '/<(' . implode( '|', array_map( 'preg_quote', $levels ) ) . ')(\s[^>]*)?>(.*?)<\/\1>/is';
    $seen     = array();
    $headings = array();

    $content = preg_replace_callback( $pattern, function ( $m ) use ( &$seen, &$headings ) {
        $tag   = strtolower( $m[1] );
        $attrs = isset( $m[2] ) ? $m[2] : '';
        $inner = $m[3];
        // Reuse an existing id if the author already set one.
        if ( preg_match( '/\sid=(["\'])(.*?)\1/i', $attrs, $id_match ) ) {
            $slug = $id_match[2];
        } else {
            $slug = sanitize_title( wp_strip_all_tags( $inner ) );
            if ( '' === $slug ) {
                $slug = 'section';
            }
            $base = $slug;
            $n = 2;
            while ( isset( $seen[ $slug ] ) ) {
                $slug = $base . '-' . $n++;
            }
            $attrs .= ' id="' . esc_attr( $slug ) . '"';
        }
        $seen[ $slug ] = true;
        $headings[] = array(
            'level' => (int) substr( $tag, 1 ),
            'id'    => $slug,
            'text'  => wp_strip_all_tags( $inner ),
        );
        return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
    }, $content );

    return array( $headings, $content );
}

/** Render the TOC box HTML for a list of headings. */
function wtoc_render_box( $headings ) {
    $settings = wtoc_settings();
    $html = '<nav class="wtoc-box" aria-label="' . esc_attr( $settings['title'] ) . '">';
    $html .= '<p class="wtoc-title">' . esc_html( $settings['title'] ) . '</p><ul class="wtoc-list">';
    $base_level = min( array_column( $headings, 'level' ) );
    foreach ( $headings as $h ) {
        // Indent deeper levels with a modifier class; CSS handles the look.
        $depth = max( 0, $h['level'] - $base_level );
        $html .= '<li class="wtoc-level-' . $depth . '"><a href="#' . esc_attr( $h['id'] ) . '">' . esc_html( $h['text'] ) . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/** Shortcode: [toc] renders the box wherever the author places it. */
function wtoc_shortcode() {
    if ( ! is_singular() ) {
        return '';
    }
    $post = get_post();
    if ( ! $post ) {
        return '';
    }
    list( $headings ) = wtoc_process_content( $post->post_content );
    $settings = wtoc_settings();
    if ( count( $headings ) < (int) $settings['min_headings'] ) {
        return '';
    }
    return wtoc_render_box( $headings );
}
add_shortcode( 'toc', 'wtoc_shortcode' );

/**
 * Auto-insert the box before the first heading and add anchor IDs.
 * Runs once per request (static guard) so the_content filters in widgets
 * or excerpts cannot double-insert it.
 */
function wtoc_auto_insert( $content ) {
    static $done = false;
    if ( $done || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }
    list( $headings, $content ) = wtoc_process_content( $content );
    $settings = wtoc_settings();
    // Manual mode: still add the anchor IDs so a [toc] shortcode placed in
    // the post links to real targets, just don't insert the box here.
    if ( empty( $settings['auto_insert'] ) ) {
        return $content;
    }
    if ( count( $headings ) < (int) $settings['min_headings'] ) {
        return $content;
    }
    $done = true;
    // Insert before the first heading tag so the box sits above the content body.
    $first_id = $headings[0]['id'];
    $pos = strpos( $content, 'id="' . $first_id . '"' );
    if ( false === $pos ) {
        return wtoc_render_box( $headings ) . $content;
    }
    $tag_start = strrpos( substr( $content, 0, $pos ), '<' );
    return substr( $content, 0, $tag_start ) . wtoc_render_box( $headings ) . substr( $content, $tag_start );
}
add_filter( 'the_content', 'wtoc_auto_insert', 9 );

/** Minimal front-end styling, enqueued only on singular pages. */
function wtoc_styles() {
    if ( ! is_singular() ) {
        return;
    }
    $css = '.wtoc-box{border:1px solid #ddd;padding:1em 1.25em;margin:0 0 1.5em;background:#fafafa}'
        . '.wtoc-box .wtoc-title{font-weight:700;margin:0 0 .5em}'
        . '.wtoc-box .wtoc-list{margin:0;padding-left:1.25em}'
        . '.wtoc-box .wtoc-level-1{margin-left:1em}.wtoc-box .wtoc-level-2{margin-left:2em}';
    wp_register_style( 'wtoc-style', false );
    wp_enqueue_style( 'wtoc-style' );
    wp_add_inline_style( 'wtoc-style', $css );
}
add_action( 'wp_enqueue_scripts', 'wtoc_styles' );

/** Settings page under Settings -> Table of Contents. */
function wtoc_register_settings() {
    register_setting( 'wtoc_settings_group', WTOC_OPTION, array(
        'sanitize_callback' => function ( $input ) {
            $allowed = array( 'h2', 'h3', 'h4' );
            $levels = array();
            if ( ! empty( $input['levels'] ) && is_array( $input['levels'] ) ) {
                foreach ( $input['levels'] as $level ) {
                    if ( in_array( strtolower( $level ), $allowed, true ) ) {
                        $levels[] = strtolower( $level );
                    }
                }
            }
            return array(
                'levels'       => ! empty( $levels ) ? $levels : array( 'h2', 'h3' ),
                'min_headings' => max( 1, (int) ( $input['min_headings'] ?? 3 ) ),
                'title'        => isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : 'Contents',
                'auto_insert'  => ! empty( $input['auto_insert'] ) ? 1 : 0,
            );
        },
    ) );
    add_options_page( __( 'Table of Contents', 'wtoc' ), __( 'Table of Contents', 'wtoc' ), 'manage_options', 'wtoc-settings', 'wtoc_settings_page' );
}
add_action( 'admin_menu', 'wtoc_register_settings' );

function wtoc_settings_page() {
    $s = wtoc_settings();
    ?>
    <div class="wrap"><h1><?php esc_html_e( 'Table of Contents', 'wtoc' ); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'wtoc_settings_group' ); ?>
        <table class="form-table">
            <tr><th scope="row"><?php esc_html_e( 'Heading levels', 'wtoc' ); ?></th>
                <td>
                <?php foreach ( array( 'h2', 'h3', 'h4' ) as $level ) : ?>
                    <label><input type="checkbox" name="<?php echo esc_attr( WTOC_OPTION ); ?>[levels][]" value="<?php echo esc_attr( $level ); ?>" <?php checked( in_array( $level, $s['levels'], true ) ); ?> /> <?php echo esc_html( strtoupper( $level ) ); ?></label>&nbsp;&nbsp;
                <?php endforeach; ?>
                </td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Minimum headings', 'wtoc' ); ?></th>
                <td><input type="number" min="1" name="<?php echo esc_attr( WTOC_OPTION ); ?>[min_headings]" value="<?php echo esc_attr( $s['min_headings'] ); ?>" /> <?php esc_html_e( 'Skip the box on posts with fewer headings.', 'wtoc' ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Box title', 'wtoc' ); ?></th>
                <td><input type="text" class="regular-text" name="<?php echo esc_attr( WTOC_OPTION ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>" /></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Auto-insert', 'wtoc' ); ?></th>
                <td><input type="checkbox" name="<?php echo esc_attr( WTOC_OPTION ); ?>[auto_insert]" value="1" <?php checked( $s['auto_insert'] ); ?> /> <?php esc_html_e( 'Insert before the first heading automatically (uncheck to use [toc] manually).', 'wtoc' ); ?></td></tr>
        </table>
        <?php submit_button(); ?>
    </form></div>
    <?php
}
