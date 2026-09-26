<?php
/**
 * Canonical VHEcoPoint brand helper.
 *
 * There is exactly ONE VHEcoPoint logo file in this project:
 *   images/logo-leaf.svg
 *
 * Every VHEcoPoint surface (sidebar, header, dashboard, Live Session,
 * rewards, admin) must render the logo through vh_eco_logo() so the file,
 * size, proportions and placement cannot drift apart between pages.
 *
 * The returned markup always carries the .vh-eco-logo class, which
 * css/ecopoint-brand.css styles once for the whole system. Do not add
 * width/height styles at the call site, and do not point this at a
 * different image.
 *
 * Platform-only surfaces (login, signup, About page, favicons, the
 * VictorianPass resident ID card) deliberately keep the platform emblem
 * images/logo.svg and must NOT use this helper.
 */

if (!defined('VH_ECO_LOGO_FILE')) {
    /** The single VHEcoPoint logo file. Relative to the app root. */
    define('VH_ECO_LOGO_FILE', 'images/logo-leaf.svg');
}

if (!function_exists('vh_eco_logo_path')) {
    /** The canonical VHEcoPoint logo path. */
    function vh_eco_logo_path()
    {
        return VH_ECO_LOGO_FILE;
    }
}

if (!function_exists('vh_eco_logo')) {
    /**
     * Render the canonical VHEcoPoint logo.
     *
     * @param string $alt    Alt text. Pass '' for decorative placements.
     * @param string $class  Extra class names appended after .vh-eco-logo.
     * @return string        The <img> markup, ready to echo.
     */
    function vh_eco_logo($alt = 'VHEcoPoint', $class = '')
    {
        $classes = 'vh-eco-logo';
        if (is_string($class) && trim($class) !== '') {
            $classes .= ' ' . trim($class);
        }

        $enc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        return '<img src="' . $enc(vh_eco_logo_path()) . '"'
             . ' alt="' . $enc($alt) . '"'
             . ' class="' . $enc($classes) . '"'
             . ' width="32" height="32" decoding="async">';
    }
}

if (!function_exists('vh_eco_lockup')) {
    /**
     * Render the canonical logo + wordmark lockup.
     *
     * @param string $main  Primary wordmark text.
     * @param string $sub   Secondary wordmark text.
     * @param string $class Extra class names for the outer lockup element.
     * @return string
     */
    function vh_eco_lockup($main = 'VHEcoPoint', $sub = 'Smart Waste Segregation Station', $class = '')
    {
        $enc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $classes = 'vh-eco-lockup';
        if (is_string($class) && trim($class) !== '') {
            $classes .= ' ' . trim($class);
        }

        $out  = '<span class="' . $enc($classes) . '">';
        $out .= vh_eco_logo('VHEcoPoint');
        $out .= '<span class="vh-eco-lockup-text">';
        $out .= '<span class="vh-eco-lockup-main">' . $enc($main) . '</span>';
        $out .= '<span class="vh-eco-lockup-sub">' . $enc($sub) . '</span>';
        $out .= '</span></span>';

        return $out;
    }
}

if (!function_exists('vh_eco_brand_stylesheet')) {
    /**
     * The <link> tag for the shared brand stylesheet.
     *
     * @return string
     */
    function vh_eco_brand_stylesheet()
    {
        return '<link rel="stylesheet" href="css/ecopoint-brand.css">';
    }
}
