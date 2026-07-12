<?php
/**
 * `[rd_catalog]` template partial — design/screens.html #3b (mobile 390),
 * #4a (tablet 834), #4b (desktop 1280: filters move into a persistent left
 * panel at ~1024px, see front-catalog.css).
 *
 * Filtering stays a plain GET form (no JS, no behavior change from the
 * pre-D3 version): the `<details>` disclosure only changes how the same
 * four filter controls are *presented* (a collapsible "Filters" chip on
 * mobile/tablet — same pattern as the theme's header.php mobile menu —
 * forced open into a sidebar card on desktop) — same param names, same
 * `Catalog_Page::read_filters()`/`Catalog_Service` on the other end. Active
 * filters also get a removable chip, each a plain link that zeroes just
 * that one query arg, so removing a filter never needs JavaScript either.
 *
 * Variables available: array<int,array{title:string,url:string,excerpt:string,style:string,level:string,terms:array}>
 * $groups, int $results_count, array{style:int,level:int,location_id:int,weekday:int} $filters,
 * array<int,string> $style_options, $level_options, array<int,array> $location_options,
 * array<int,string> $weekday_options, string $page_url.
 *
 * @package RubenDance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

$ruben_dance_active_count = 0;
foreach ( array( 'style', 'level', 'location_id', 'weekday' ) as $ruben_dance_key ) {
	if ( 0 !== (int) $filters[ $ruben_dance_key ] ) {
		++$ruben_dance_active_count;
	}
}

/**
 * Build a catalog URL with the current filters, overriding one key.
 *
 * @param string $key   Filter key to override.
 * @param int    $value New value for that key (0 clears it).
 * @return string
 */
$ruben_dance_filter_url = static function ( string $key, int $value ) use ( $page_url, $filters ): string {
	$args         = $filters;
	$args[ $key ] = $value;
	$args         = array_filter( $args, static fn( $v ): bool => 0 !== (int) $v );

	return array() === $args ? $page_url : add_query_arg( $args, $page_url );
};

$ruben_dance_active_location_name = '';
foreach ( $location_options as $ruben_dance_location ) {
	if ( (int) $ruben_dance_location['id'] === $filters['location_id'] ) {
		$ruben_dance_active_location_name = (string) $ruben_dance_location['name'];
		break;
	}
}
?>
<div class="rd-app rd-catalog">
	<?php if ( $ruben_dance_active_count > 0 ) : ?>
		<div class="rd-catalog__active-chips">
			<?php if ( 0 !== $filters['style'] && isset( $style_options[ $filters['style'] ] ) ) : ?>
				<a class="rd-filter-chip rd-filter-chip--active" href="<?php echo esc_url( $ruben_dance_filter_url( 'style', 0 ) ); ?>"><?php echo esc_html( $style_options[ $filters['style'] ] ); ?> ✕</a>
			<?php endif; ?>
			<?php if ( 0 !== $filters['level'] && isset( $level_options[ $filters['level'] ] ) ) : ?>
				<a class="rd-filter-chip rd-filter-chip--active" href="<?php echo esc_url( $ruben_dance_filter_url( 'level', 0 ) ); ?>"><?php echo esc_html( $level_options[ $filters['level'] ] ); ?> ✕</a>
			<?php endif; ?>
			<?php if ( '' !== $ruben_dance_active_location_name ) : ?>
				<a class="rd-filter-chip rd-filter-chip--active" href="<?php echo esc_url( $ruben_dance_filter_url( 'location_id', 0 ) ); ?>"><?php echo esc_html( $ruben_dance_active_location_name ); ?> ✕</a>
			<?php endif; ?>
			<?php if ( 0 !== $filters['weekday'] && isset( $weekday_options[ $filters['weekday'] ] ) ) : ?>
				<a class="rd-filter-chip rd-filter-chip--active" href="<?php echo esc_url( $ruben_dance_filter_url( 'weekday', 0 ) ); ?>"><?php echo esc_html( $weekday_options[ $filters['weekday'] ] ); ?> ✕</a>
			<?php endif; ?>
			<a class="rd-btn rd-btn--text rd-catalog__clear-all" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Clear all', 'ruben-dance' ); ?></a>
		</div>
	<?php endif; ?>

	<div class="rd-catalog__layout">
		<details class="rd-catalog__filters">
			<summary class="rd-catalog__filters-toggle">
				<span class="rd-filter-chip<?php echo $ruben_dance_active_count > 0 ? ' rd-filter-chip--active' : ''; ?> rd-catalog__filters-chip">
					<?php esc_html_e( 'Filters', 'ruben-dance' ); ?>
					<?php if ( $ruben_dance_active_count > 0 ) : ?>
						<span class="rd-filter-chip__count"><?php echo esc_html( (string) $ruben_dance_active_count ); ?></span>
					<?php endif; ?>
				</span>
				<span class="rd-catalog__filters-heading"><?php esc_html_e( 'Filters', 'ruben-dance' ); ?></span>
			</summary>

			<form method="get" action="<?php echo esc_url( $page_url ); ?>" class="rd-catalog__filter-form">
				<div class="rd-catalog__filter-group">
					<span class="rd-eyebrow"><?php esc_html_e( 'Dance style', 'ruben-dance' ); ?></span>
					<div class="rd-catalog__check-list">
						<?php // Radio inputs (the filter is single-select, see Catalog_Page::read_filters()) drawn as #4b's square check boxes. ?>
						<label class="rd-catalog__check-row">
							<input type="radio" name="style" value="0" <?php checked( $filters['style'], 0 ); ?>>
							<span class="rd-catalog__check-box" aria-hidden="true"></span>
							<?php esc_html_e( 'All', 'ruben-dance' ); ?>
						</label>
						<?php foreach ( $style_options as $ruben_dance_id => $ruben_dance_name ) : ?>
							<label class="rd-catalog__check-row">
								<input type="radio" name="style" value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>" <?php checked( $filters['style'], $ruben_dance_id ); ?>>
								<span class="rd-catalog__check-box" aria-hidden="true"></span>
								<?php echo esc_html( $ruben_dance_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="rd-catalog__filter-group">
					<span class="rd-eyebrow"><?php esc_html_e( 'Level', 'ruben-dance' ); ?></span>
					<div class="rd-catalog__chip-row">
						<label class="rd-catalog__radio-chip">
							<input type="radio" name="level" value="0" <?php checked( $filters['level'], 0 ); ?>>
							<span class="rd-filter-chip<?php echo 0 === $filters['level'] ? ' rd-filter-chip--active' : ''; ?>"><?php esc_html_e( 'All', 'ruben-dance' ); ?></span>
						</label>
						<?php foreach ( $level_options as $ruben_dance_id => $ruben_dance_name ) : ?>
							<label class="rd-catalog__radio-chip">
								<input type="radio" name="level" value="<?php echo esc_attr( (string) $ruben_dance_id ); ?>" <?php checked( $filters['level'], $ruben_dance_id ); ?>>
								<span class="rd-filter-chip<?php echo $filters['level'] === $ruben_dance_id ? ' rd-filter-chip--active' : ''; ?>"><?php echo esc_html( $ruben_dance_name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="rd-catalog__filter-group">
					<label class="rd-eyebrow" for="rd-catalog-location"><?php esc_html_e( 'Location', 'ruben-dance' ); ?></label>
					<select name="location_id" id="rd-catalog-location" class="rd-catalog__select">
						<option value="0"><?php esc_html_e( 'All locations', 'ruben-dance' ); ?></option>
						<?php foreach ( $location_options as $ruben_dance_location ) : ?>
							<option value="<?php echo esc_attr( (string) $ruben_dance_location['id'] ); ?>" <?php selected( $filters['location_id'], (int) $ruben_dance_location['id'] ); ?>><?php echo esc_html( (string) $ruben_dance_location['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="rd-catalog__filter-group">
					<span class="rd-eyebrow"><?php esc_html_e( 'Weekday', 'ruben-dance' ); ?></span>
					<div class="rd-catalog__chip-row">
						<label class="rd-catalog__radio-chip">
							<input type="radio" name="weekday" value="0" <?php checked( $filters['weekday'], 0 ); ?>>
							<span class="rd-filter-chip<?php echo 0 === $filters['weekday'] ? ' rd-filter-chip--active' : ''; ?>"><?php esc_html_e( 'Any', 'ruben-dance' ); ?></span>
						</label>
						<?php foreach ( $weekday_options as $ruben_dance_number => $ruben_dance_label ) : ?>
							<label class="rd-catalog__radio-chip">
								<input type="radio" name="weekday" value="<?php echo esc_attr( (string) $ruben_dance_number ); ?>" <?php checked( $filters['weekday'], $ruben_dance_number ); ?>>
								<span class="rd-filter-chip<?php echo $filters['weekday'] === $ruben_dance_number ? ' rd-filter-chip--active' : ''; ?>"><?php echo esc_html( mb_substr( $ruben_dance_label, 0, 2 ) ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="rd-catalog__filter-actions">
					<button type="submit" class="rd-btn rd-btn--primary"><?php esc_html_e( 'Show results', 'ruben-dance' ); ?></button>
					<?php if ( $ruben_dance_active_count > 0 ) : ?>
						<a class="rd-btn rd-btn--text" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Clear all', 'ruben-dance' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</details>

		<div class="rd-catalog__results">
			<p class="rd-catalog__count rd-text">
				<?php
				printf(
					/* translators: %d: number of open terms found. */
					esc_html( _n( '%d result', '%d results', $results_count, 'ruben-dance' ) ),
					(int) $results_count
				);
				?>
			</p>

			<?php if ( array() === $groups ) : ?>
				<div class="rd-empty-state">
					<div class="rd-empty-state__icon" aria-hidden="true">🕺</div>
					<p class="rd-empty-state__text"><?php esc_html_e( 'No courses match your filters', 'ruben-dance' ); ?></p>
					<?php if ( $ruben_dance_active_count > 0 ) : ?>
						<a class="rd-empty-state__action" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Clear filters', 'ruben-dance' ); ?></a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="rd-catalog__grid">
					<?php foreach ( $groups as $ruben_dance_group ) : ?>
						<?php
						$ruben_dance_workshop_only = array() !== $ruben_dance_group['terms'];
						foreach ( $ruben_dance_group['terms'] as $ruben_dance_check_term ) {
							if ( 'workshop' !== $ruben_dance_check_term['type'] ) {
								$ruben_dance_workshop_only = false;
								break;
							}
						}
						?>
						<article class="rd-card rd-cat-course<?php echo $ruben_dance_workshop_only ? ' rd-cat-course--workshop' : ''; ?>">
							<?php if ( ! $ruben_dance_workshop_only ) : ?>
								<a class="rd-cat-course__photo" href="<?php echo esc_url( $ruben_dance_group['url'] ); ?>" style="background-image:url('<?php echo esc_attr( $ruben_dance_group['photo'] ); ?>')">
									<span class="screen-reader-text"><?php echo esc_html( $ruben_dance_group['title'] ); ?></span>
									<?php if ( '' !== $ruben_dance_group['style'] || '' !== $ruben_dance_group['level'] ) : ?>
										<span class="rd-cat-course__photo-badges" aria-hidden="true">
											<?php if ( '' !== $ruben_dance_group['style'] ) : ?>
												<span class="rd-cat-course__badge"><?php echo esc_html( $ruben_dance_group['style'] ); ?></span>
											<?php endif; ?>
											<?php if ( '' !== $ruben_dance_group['level'] ) : ?>
												<span class="rd-cat-course__badge rd-cat-course__badge--level"><?php echo esc_html( $ruben_dance_group['level'] ); ?></span>
											<?php endif; ?>
										</span>
									<?php endif; ?>
								</a>
							<?php endif; ?>
							<div class="rd-cat-course__body">
								<?php if ( $ruben_dance_workshop_only ) : ?>
									<span class="rd-badge rd-badge--workshop"><?php esc_html_e( 'Workshop — one-off event', 'ruben-dance' ); ?></span>
								<?php endif; ?>
								<h2 class="rd-h3 rd-cat-course__title"><a href="<?php echo esc_url( $ruben_dance_group['url'] ); ?>"><?php echo esc_html( $ruben_dance_group['title'] ); ?></a></h2>
								<?php if ( ! $ruben_dance_workshop_only && '' !== $ruben_dance_group['excerpt'] ) : ?>
									<p class="rd-text rd-cat-course__excerpt"><?php echo esc_html( $ruben_dance_group['excerpt'] ); ?></p>
								<?php endif; ?>

								<div class="rd-term-list">
									<?php foreach ( $ruben_dance_group['terms'] as $ruben_dance_term ) : ?>
										<div class="rd-term-card">
											<div class="rd-term-card__info">
												<p class="rd-term-card__schedule"><strong><?php echo esc_html( trim( $ruben_dance_term['weekday'] . ' ' . $ruben_dance_term['time'] ) ); ?></strong> · <?php echo esc_html( $ruben_dance_term['season'] . ( '' !== $ruben_dance_term['location'] ? ' · ' . $ruben_dance_term['location'] : '' ) ); ?></p>
												<p class="rd-term-card__price">
													<?php if ( null !== $ruben_dance_term['early_bird'] ) : ?>
														<span class="rd-term-card__price-now rd-term-card__price-now--early"><?php echo esc_html( $ruben_dance_term['early_bird']['price'] ); ?> Kč</span>
														<span class="rd-price__base-strike"><?php echo esc_html( $ruben_dance_term['price'] ); ?> Kč</span>
														<span class="rd-badge rd-badge--early">
															<?php
															printf(
																/* translators: %s: early-bird deadline date. */
																esc_html__( 'Early-bird until %s', 'ruben-dance' ),
																esc_html( $ruben_dance_term['early_bird']['until'] )
															);
															?>
														</span>
													<?php else : ?>
														<span class="rd-term-card__price-now"><?php echo esc_html( $ruben_dance_term['price'] ); ?> Kč</span>
													<?php endif; ?>
													<?php if ( $ruben_dance_term['is_full'] ) : ?>
														<span class="rd-badge rd-badge--full"><?php esc_html_e( "Full — we'll contact you", 'ruben-dance' ); ?></span>
													<?php endif; ?>
												</p>
											</div>
											<a class="rd-btn <?php echo $ruben_dance_term['is_full'] ? 'rd-btn--secondary' : 'rd-btn--primary'; ?> rd-term-card__cta" href="<?php echo esc_url( $ruben_dance_term['enroll_url'] ); ?>"><?php esc_html_e( 'Enroll', 'ruben-dance' ); ?></a>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
