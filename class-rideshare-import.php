<?php
/**
 * RideShare_Import class file.
 *
 * @package RideShareImporter
 */

namespace RideShareImporter;

/**
 * Class RideShare_Import
 */
class RideShare_Import {

	/**
	 * Uploaded file path.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Uploaded file attachment id.
	 *
	 * @var number
	 */
	private $id;

	/**
	 * Drivers by id.
	 *
	 * @var array
	 */
	public $drivers = array();

	/**
	 * Cities by id.
	 *
	 * @var array
	 */
	public $cities = array();

	/**
	 * An array of all trips.
	 *
	 * @var array
	 */
	public $trips = array();


	/**
	 * Render header.
	 */
	private function header() {
		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Import RideShare', 'rideshare-importer' ) . '</h2>';
	}

	/**
	 * Render footer.
	 */
	private function footer() {
		echo '</div>';
	}

	/**
	 * Render usage guide.
	 */
	private function greet() {
		?>
		<div class="narrow">
		<p><?php esc_html_e( 'Upload your RideShare JSON to import rides into this blog.', 'rideshare-importer' ); ?></p>
		<?php wp_import_upload_form( 'admin.php?import=rideshare&amp;step=1' ); ?>
		</div>
		<h3><?php esc_html_e( 'How to get the JSON export?', 'rideshare-importer' ); ?></h3><ol>
		<p><?php esc_html_e( 'Uber doesn\'t allow you to conveniently export your data including maps so this plugin uses data gathered using a browser extension.', 'rideshare-importer' ); ?></p>
		<li><?php esc_html_e( 'Install the browser extension' ); ?> <a href="https://github.com/jonluca/RideShare-Trip-Stats">RideShareStats browser extension</a></li>
		<li><?php esc_html_e( 'Go to your Uber rider history' ); ?> <a href="https://riders.uber.com/trips">https://riders.uber.com/trips</a></li>
		<li><?php esc_html_e( 'Run the extension from the browser toolbar', 'rideshare-importer' ); ?></li>
		<li><?php esc_html_e( 'When asked "Request individual trip data?", pick "YES"', 'rideshare-importer' ); ?></li>
		<li><?php esc_html_e( 'A new RideShare Stats page will open up once all data is downloaded', 'rideshare-importer' ); ?></li>
		<li><?php esc_html_e( 'At the bottom of the page, there is an "Export" button. Use it with option "JSON (Full Data)"', 'rideshare-importer' ); ?></li>
		<li><?php esc_html_e( 'Upload your JSON file and use it in this importer', 'rideshare-importer' ); ?></li>
		</ol>
		<?php
	}

	/**
	 * Get city name by its id.
	 *
	 * @param string $id id of the city.
	 * @return string Name of the city.
	 */
	private function get_city_name( $id ) {
		return $this->cities[ $id ]['name'];
	}

	/**
	 * Get driver name by its id.
	 *
	 * @param string $id id of the driver.
	 * @return string Name of the driver.
	 */
	private function get_driver_name( $id ) {
		return $this->drivers[ $id ]['firstname'];
	}

	/**
	 * Process and import a single trip.
	 *
	 * @param array $trip Trip.
	 */
	private function process_trip( $trip ) {
		global $wpdb;

		// Check for post matching the id of current entry.
		// If found, the post will be updated, otherwise a new one created.
		$query            = new \WP_Query(
			array(
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'rideshare_id',
						'value'   => $trip['uuid'],
						'compare' => '=',
					),
				),
				'posts_per_page' => 1,
			)
		);
		$already_existing = null;
		if ( $query->found_posts > 0 ) {
			$already_existing = $query->posts[0]->ID;
		}

		// Don't save incomplete trips.
		if ( 'COMPLETED' !== $trip['status'] ) {
			return;
		}

		// Some service types already include "uber" in it. Like UberX.
		// This adds it for other services that just say "Select" or "Pool".
		$service = $trip['vehicleViewName'];
		if ( strpos( strtolower( $service ), 'uber' ) === false ) {
			$service = 'Uber ' . $service;
		}

		// Enhance trip data with Driver and City names.
		$trip['driver'] = $this->get_driver_name( $trip['driverUUID'] );
		$trip['city']   = $this->get_city_name( $trip['cityID'] );

		// If map link present, extract coordinates from it.
		$querystring = wp_parse_url( $trip['tripMap']['url'], PHP_URL_QUERY );
		$query       = array();
		parse_str( $querystring, $query );
		$polyline = explode( 'enc:', $query['path'] )[1];

		// Contruct post title.
		$post_title = sprintf(
		/* translators: 1: Service name (like "Uber Pool"), 2: Name of a city */
			__( 'Rode %1$s in %2$s', 'rideshare-importer' ),
			$service,
			$trip['city']
		);

		/**
		 * Filter the post title for a trip.
		 *
		 * @param string $post_title Post title.
		 * @param string $service Service name.
		 * @param string $city City name.
		 * @param array $trip Trip object with all details.
		 */
		$post_title = apply_filters( 'rideshare_importer_trip_title', $post_title, $service, $trip['city'], $trip );

		$post_content = '';

		// Add map image if present.
		if ( ! empty( $trip['tripMap']['url'] ) ) {
			$post_content .= '<img src="' . esc_attr( $trip['tripMap']['url'] ) . '">';
		}

		// Format addresses.
		$post_content .= '<ul>';
		$post_content .= '<li>' . esc_html(
			sprintf(
			/* translators: %s: Full address of pickup. */
				__( 'Pickup: %s', 'rideshare-importer' ),
				$trip['begintripFormattedAddress']
			)
		) . '</li>';
		$post_content .= '<li>' . esc_html(
			sprintf(
			/* translators: %s: Full address of dropoff. */
				__( 'Dropoff: %s', 'rideshare-importer' ),
				$trip['dropoffFormattedAddress']
			)
		) . '</li>';
		$post_content .= '</ul>';

		// Format meta data.
		$post_content .= '<dl>';

		if ( ! empty( $trip['receipt']->car_make ) ) {
			$post_content .= '<dt>' . esc_html( ! empty( $trip['receipt']['car_make_label'] ) ? ucfirst( $trip['receipt']['car_make_label'] ) : __( 'Car Make', 'rideshare-importer' ) ) . '</dt>';
			$post_content .= '<dd>' . esc_html( $trip['receipt']['car_make'] ) . '</dd>';
		}

		if ( ! empty( $trip['receipt']['duration'] ) ) {
			$post_content .= '<dt>' . esc_html( ! empty( $trip['receipt']['trip_time_label'] ) ? ucfirst( $trip['receipt']['trip_time_label'] ) : __( 'Time', 'rideshare-importer' ) ) . '</dt>';
			$post_content .= '<dd>' . esc_html( $trip['receipt']['duration'] ) . '</dd>';
		}

		if ( ! empty( $trip['receipt']['distance'] ) ) {
			$post_content .= '<dt>' . esc_html( ! empty( $trip['receipt']['distance_label'] ) ? ucfirst( $trip['receipt']['distance_label'] ) : __( 'Distance', 'rideshare-importer' ) ) . '</dt>';
			$post_content .= '<dd>' . esc_html( $trip['receipt']['distance'] ) . '</dd>';
		}

		if ( ! empty( $trip['driver'] ) ) {
			$post_content .= '<dt>' . esc_html__( 'Driver', 'rideshare-importer' ) . '</dt>';
			$post_content .= '<dd>' . esc_html( $trip['driver'] ) . '</dd>';
		}

		if ( ! empty( $trip['clientFare'] ) && ! empty( $trip['currencyCode'] ) ) {
			$post_content .= '<dt>' . esc_html__( 'Fare', 'rideshare-importer' ) . '</dt>';
			$post_content .= '<dd>' . esc_html( $trip['currencyCode'] ) . ' ' . esc_html( $trip['clientFare'] ) . '</dd>';
		}

		$post_content .= '</dl>';

		/**
		 * Filter the post content.
		 *
		 * @param array $post_content The post content.
		 * @param array $trip Trip object with all details.
		 */
		$post_content = apply_filters( 'rideshare_importer_trip_content', $post_content, $trip );

		/**
		 * Filter the post object before inserting.
		 *
		 * @param array $post The post.
		 * @param array $trip Trip object with all details.
		 */
		$post = apply_filters(
			'rideshare_importer_trip_post',
			array(
				'ID'           => $already_existing,
				'post_status'  => 'publish',
				'post_title'   => $post_title,
				'post_content' => $post_content,
				'post_date'    => $trip['requestTime'],
			),
			$trip
		);

		// Upsert post.
		$post_id = wp_insert_post( $post );

		// Save more details in post meta.
		update_post_meta( $post_id, 'rideshare_provider', 'uber', true );
		update_post_meta( $post_id, 'rideshare_id', $trip['uuid'], true );
		update_post_meta( $post_id, 'rideshare_type', $service, true );
		update_post_meta( $post_id, 'raw_import_data', wp_slash( wp_json_encode( $trip ) ) );

		// Save map coords, if available.
		if ( $polyline ) {
			update_post_meta( $post_id, 'geo_polyline_encoded', wp_slash( $polyline ) );
			update_post_meta( $post_id, 'geo_public', '1' );
		}

		// Assign driver profile using People & Places plugin, if present.
		if ( ! empty( $trip['driver'] ) && class_exists( 'People_Places' ) ) {
			$driver = array(
				/* translators: %s: first name of the driver. */
				'name' => sprintf( __( 'Driver %s', 'rideshare-importer' ), $trip['driver'] ),
			);
			\People_Places::add_person_to_post( 'uber', $trip['driverUUID'], $driver, $post_id );
		}

		/**
		 * Runs after saving the post and recording its post_meta.
		 *
		 * @param int $post_id The post id.
		 * @param array $trip Trip object with all details.
		 */
		do_action( 'rideshare_importer_post_inserted', $post_id, $trip );
	}

	/**
	 * Begin importing process after uploading a file.
	 */
	private function import() {
		// Handle upload.
		$file = wp_import_handle_upload();
		if ( isset( $file['error'] ) ) {
			echo '<p>' . esc_html__( 'Sorry, there has been an error.', 'rideshare-importer' ) . '</p>';
			echo '<p><strong>' . esc_html( $file['error'] ) . '</strong></p>';
			return;
		}
		$this->file = $file['file'];
		$this->id   = (int) $file['id'];

		// Parse uploaded data.
		$data = json_decode( file_get_contents( $this->file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Store entities keyed by ids for an easier access later.
		foreach ( $data['drivers'] as $driver ) {
			$this->drivers[ $driver['uuid'] ] = $driver;
		}
		foreach ( $data['cities'] as $city ) {
			$this->cities[ $city['id'] ] = $city;
		}

		// Store trips.
		$this->trips = $data['trips'];

		echo '<ol>';

		// Import trips.
		foreach ( $this->trips as $trip ) {
			$this->process_trip( $trip );

			echo '<li>';
			/* translators: %s: trip id */
			printf( esc_html__( 'Importing %s.', 'rideshare-importer' ), esc_html( $trip['uuid'] ) );
		}

		echo '</ol>';

		wp_import_cleanup( $this->id );

		echo '<h3>' . sprintf( esc_html__( 'All done.', 'rideshare-importer' ) . ' <a href="%s">' . esc_html__( 'Have fun!', 'rideshare-importer' ) . '</a>', esc_attr( get_option( 'home' ) ) ) . '</h3>';
	}

	/**
	 * Dispatch actions.
	 */
	public function dispatch() {
		if ( empty( $_GET['step'] ) ) {
			$step = 0;
		} else {
			$step = (int) $_GET['step'];
		}

		$this->header();
		switch ( $step ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				$this->import();
				break;
		}
		$this->footer();
	}
}
