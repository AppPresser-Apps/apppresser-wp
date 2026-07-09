<?php
/**
 * Server-side render callback for the Accessibility Button block.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the accessibility trigger button.
 *
 * @param array $attributes Block attributes.
 * @return string Button HTML.
 */
$label     = isset( $attributes['label'] ) ? esc_html( $attributes['label'] ) : '';
$has_label = '' !== trim( $label );

$placement          = isset( $attributes['placement'] ) ? $attributes['placement'] : 'left_bottom';
$allowed_placements = array( 'left_bottom', 'right_bottom', 'left_top', 'right_top' );
if ( ! in_array( $placement, $allowed_placements, true ) ) {
	$placement = 'left_bottom';
}

$icon_name     = isset( $attributes['icon'] ) ? $attributes['icon'] : 'accessibility';
$allowed_icons = array( 'accessibility', 'eye', 'contrast', 'text', 'none' );
if ( ! in_array( $icon_name, $allowed_icons, true ) ) {
	$icon_name = 'accessibility';
}

$size_name     = isset( $attributes['size'] ) ? $attributes['size'] : 'medium';
$allowed_sizes = array( 'small', 'medium', 'large' );
if ( ! in_array( $size_name, $allowed_sizes, true ) ) {
	$size_name = 'medium';
}

$bg_color = isset( $attributes['backgroundColor'] ) ? $attributes['backgroundColor'] : '';
$padding  = isset( $attributes['padding'] ) ? absint( $attributes['padding'] ) : 8;

$icon_markup = '';
if ( 'none' !== $icon_name ) {
	switch ( $icon_name ) {
		case 'accessibility':
			$icon_paths = '<circle cx="50" cy="22" r="10" /><path d="M15 35 L50 45 L85 35 M50 45 L50 65 M30 90 L50 65 L70 90" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round" />';
			break;
		case 'eye':
			$icon_paths = '<path d="M10 50 Q30 25 50 50 Q70 75 90 50 Q70 25 50 50 Q30 75 10 50 Z"/><circle cx="50" cy="50" r="12"/>';
			break;
		case 'contrast':
			$icon_paths = '<circle cx="50" cy="50" r="38"/><path d="M50 12 A38 38 0 0 0 50 88 A38 38 0 0 1 50 12 Z" fill="#fff"/>';
			break;
		case 'text':
			$icon_paths = '<rect x="20" y="18" width="60" height="12" rx="2"/><rect x="20" y="36" width="48" height="12" rx="2"/><rect x="20" y="54" width="56" height="12" rx="2"/><rect x="20" y="72" width="36" height="12" rx="2"/>';
			break;
		default:
			$icon_paths = '';
			break;
	}

	if ( '' !== $icon_paths ) {
		$icon_markup = '<span class="appp-a11y-trigger__icon" aria-hidden="true">' .
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' .
				$icon_paths .
			'</svg>' .
		'</span>';
	}
}

// Build inline styles.
$inline_styles = '';
if ( '' !== $bg_color ) {
	$inline_styles .= 'background:' . esc_attr( $bg_color ) . ';';
}
$inline_styles .= 'padding:' . esc_attr( $padding ) . 'px;';

// Build CSS classes.
$classes = 'appp-a11y-trigger appp-a11y-trigger--' . $placement . ' appp-a11y-trigger--' . $size_name;
if ( ! $has_label ) {
	$classes .= ' appp-a11y-trigger--icon-only';
}

printf(
	'<button type="button" class="%1$s" id="appp-a11y-trigger" aria-label="%2$s" style="%3$s">%4$s%5$s</button>',
	esc_attr( $classes ),
	esc_attr( $label ),
	$inline_styles, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — Already escaped above.
	$icon_markup, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG is hardcoded and safe.
	$label
);
