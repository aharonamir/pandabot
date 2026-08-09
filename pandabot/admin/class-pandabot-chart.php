<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline-SVG charts for the dashboard. No chart library, no CDN (plan §8).
 *
 * Deliberately only does single-series magnitude-over-time: one hue, no
 * legend (the card heading names the series), no second y-axis, a recessive
 * baseline, and a direct label only on the peak rather than on every bar.
 * Hover text is a native <title> element, so the charts need zero JS.
 */
class Pandabot_Chart {

	/**
	 * @param array<string,int> $series date (Y-m-d) => value
	 * @param array             $args   color, height, empty_text
	 */
	public static function bars( array $series, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'color'      => '#2271b1',
				'height'     => 120,
				'empty_text' => __( 'No data in this period yet.', 'pandabot' ),
			)
		);

		$count = count( $series );
		$max   = $count ? max( $series ) : 0;

		if ( 0 === $count || 0 === $max ) {
			return '<p class="pandabot-chart-empty">' . esc_html( $args['empty_text'] ) . '</p>';
		}

		$plot_w = 640;
		$plot_h = (int) $args['height'];
		$pad_b  = 18;
		$step   = $plot_w / $count;
		$bar_w  = max( 1.5, $step - 2 ); // 2px surface gap between adjacent bars.

		$dates = array_keys( $series );
		$label = sprintf(
			/* translators: 1: first date in range, 2: last date, 3: peak value */
			__( '%1$s to %2$s, peak %3$s', 'pandabot' ),
			self::day_label( reset( $dates ) ),
			self::day_label( end( $dates ) ),
			number_format_i18n( $max )
		);

		// No preserveAspectRatio="none": non-uniform scaling would stretch the
		// axis labels horizontally. The CSS caps the width so the uniform
		// scale factor stays close to 1 and the 11px type stays 11px-ish.
		$svg  = '<svg class="pandabot-chart" viewBox="0 0 ' . $plot_w . ' ' . ( $plot_h + $pad_b ) . '" role="img" aria-label="' . esc_attr( $label ) . '">';
		$svg .= '<line x1="0" y1="' . $plot_h . '" x2="' . $plot_w . '" y2="' . $plot_h . '" stroke="#dcdcde" stroke-width="1" />';

		$i          = 0;
		$peak_x     = 0;
		$peak_y     = $plot_h;
		$peak_found = false;
		$last_idx   = $count - 1;

		foreach ( $series as $date => $value ) {
			$h = ( $value > 0 ) ? max( 2, (int) round( ( $value / $max ) * ( $plot_h - 6 ) ) ) : 0;
			$x = $i * $step;
			$y = $plot_h - $h;

			if ( $h > 0 ) {
				$r    = min( 3, $bar_w / 2, $h );
				$svg .= '<path d="' . self::top_rounded_path( $x, $y, $bar_w, $h, $r, $plot_h ) . '" fill="' . esc_attr( $args['color'] ) . '">';
				$svg .= '<title>' . esc_html( self::day_label( $date ) . ' — ' . number_format_i18n( $value ) ) . '</title>';
				$svg .= '</path>';

				if ( ! $peak_found && $value === $max ) {
					$peak_found = true;
					$peak_x     = $x + ( $bar_w / 2 );
					$peak_y     = $y;
				}
			}

			if ( 0 === $i || $last_idx === $i ) {
				$anchor = ( 0 === $i ) ? 'start' : 'end';
				$tx     = ( 0 === $i ) ? 0 : $plot_w;
				$svg   .= '<text x="' . $tx . '" y="' . ( $plot_h + 13 ) . '" text-anchor="' . $anchor . '" font-size="11" fill="#787c82">' . esc_html( self::day_label( $date ) ) . '</text>';
			}

			$i++;
		}

		// Selective direct label: the peak only, never every bar.
		$label_anchor = ( $peak_x > $plot_w * 0.9 ) ? 'end' : ( ( $peak_x < $plot_w * 0.1 ) ? 'start' : 'middle' );
		$svg         .= '<text x="' . $peak_x . '" y="' . max( 11, $peak_y - 4 ) . '" text-anchor="' . $label_anchor . '" font-size="11" font-weight="600" fill="#1d2327">' . esc_html( number_format_i18n( $max ) ) . '</text>';
		$svg         .= '</svg>';

		return $svg;
	}

	/**
	 * Bars are rounded at the data end only — the baseline end stays square
	 * so every bar is anchored to the same visual floor.
	 */
	private static function top_rounded_path( $x, $y, $w, $h, $r, $baseline ) {
		$x  = round( $x, 2 );
		$y  = round( $y, 2 );
		$w  = round( $w, 2 );
		$r  = round( $r, 2 );
		$x2 = round( $x + $w, 2 );

		return "M{$x},{$baseline} V" . round( $y + $r, 2 ) . " Q{$x},{$y} " . round( $x + $r, 2 ) . ",{$y} H" . round( $x2 - $r, 2 ) . " Q{$x2},{$y} {$x2}," . round( $y + $r, 2 ) . " V{$baseline} Z";
	}

	private static function day_label( $date ) {
		return date_i18n( 'j M', strtotime( $date . ' 12:00:00' ) );
	}

	/**
	 * Horizontal share bar used by the funnel and the guardrail breakdown —
	 * magnitude in a single hue, with the number always shown as text so
	 * the bar is never the only encoding.
	 */
	public static function share_bar( $value, $total, $color = '#2271b1' ) {
		$percent = ( $total > 0 ) ? min( 100, ( $value / $total ) * 100 ) : 0;

		return '<span class="pandabot-share"><span class="pandabot-share-fill" style="width:' . esc_attr( round( $percent, 1 ) ) . '%;background:' . esc_attr( $color ) . '"></span></span>';
	}
}
