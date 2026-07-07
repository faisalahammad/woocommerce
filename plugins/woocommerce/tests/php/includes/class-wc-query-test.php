<?php

declare( strict_types = 1 );

/**
 * Tests for WC_Query.
 */
class WC_Query_Test extends \WC_Unit_Test_Case {

	/**
	 * @testdox 'price_filter_post_clauses' generates the proper 'where' clause when there are 'max_price' and 'min_price' arguments in the query.
	 */
	public function test_price_filter_post_clauses_creates_the_proper_where_clause() {
		// phpcs:disable Squiz.Commenting
		$wp_query = new class() {
			public function is_main_query() {
				return true;
			}
		};
		// phpcs:enable Squiz.Commenting

		$_GET['min_price'] = '100';
		$_GET['max_price'] = '200';

		$sut = new WC_Query();

		$args = array(
			'join'  => '(JOIN CLAUSE)',
			'where' => '(WHERE CLAUSE)',
		);

		$args     = $sut->price_filter_post_clauses( $args, $wp_query );
		$expected = '(WHERE CLAUSE) AND NOT (200.000000<wc_product_meta_lookup.min_price OR 100.000000>wc_product_meta_lookup.max_price ) ';

		$this->assertEquals( $expected, $args['where'] );
	}

	/**
	 * @testdox Shop page can be set as the homepage on block themes.
	 */
	public function test_shop_page_in_home_displays_correctly() {
		switch_theme( 'twentytwentyfour' );

		// Create a page and use it as the Shop page.
		$shop_page_id                     = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Shop',
			)
		);
		$default_woocommerce_shop_page_id = get_option( 'woocommerce_shop_page_id' );
		update_option( 'woocommerce_shop_page_id', $shop_page_id );

		// Set the Shop page as the homepage.
		$default_show_on_front = get_option( 'show_on_front' );
		$default_page_on_front = get_option( 'page_on_front' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $shop_page_id );

		// Simulate the main query.
		$query = new WP_Query(
			array(
				'post_type' => 'page',
				'page_id'   => $shop_page_id,
			)
		);
		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;
		$wp_the_query          = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$query->get_posts();

		$this->assertTrue( defined( 'SHOP_IS_ON_FRONT' ) && SHOP_IS_ON_FRONT );
		$this->assert_shop_page_queried_object( $query, $shop_page_id );

		// Reset main query, options and delete the page we created.
		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		update_option( 'woocommerce_shop_page_id', $default_woocommerce_shop_page_id );
		update_option( 'show_on_front', $default_show_on_front );
		update_option( 'page_on_front', $default_page_on_front );
		wp_delete_post( $shop_page_id, true );
	}

	/**
	 * @testdox Product archive queries set queried_object to the Shop page.
	 */
	public function test_shop_page_sets_queried_object_on_product_archive(): void {
		$shop_page_id         = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Shop',
			)
		);
		$default_shop_page_id = get_option( 'woocommerce_shop_page_id' );
		update_option( 'woocommerce_shop_page_id', $shop_page_id );

		$query                       = new WP_Query(
			array(
				'post_type' => 'product',
			)
		);
		$query->is_post_type_archive = true;
		$query->is_archive           = true;
		$query->is_tax               = false;
		$query->is_home              = false;

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;
		$wp_the_query          = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query              = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->get_posts();

		$this->assert_shop_page_queried_object( $query, $shop_page_id );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		update_option( 'woocommerce_shop_page_id', $default_shop_page_id );
		wp_delete_post( $shop_page_id, true );
	}

	/**
	 * Assert that a query's queried object matches the configured Shop page.
	 *
	 * @param WP_Query $query        The query to inspect.
	 * @param int      $shop_page_id The expected Shop page ID.
	 */
	private function assert_shop_page_queried_object( WP_Query $query, int $shop_page_id ): void {
		$this->assertInstanceOf( WP_Post::class, $query->queried_object, 'queried_object should be a WP_Post instance.' );
		$this->assertSame( $shop_page_id, $query->queried_object->ID, 'queried_object ID should match the Shop page ID.' );
		$this->assertSame( $shop_page_id, $query->queried_object_id, 'queried_object_id should match the Shop page ID.' );
	}

	/**
	 * Data provider for search ordering tests.
	 *
	 * @return array[] Each entry: [ search query string, whether relevance ordering is expected, description ].
	 */
	public function data_provider_search_ordering(): array {
		return array(
			'normal search'              => array( 'shirt', true, 'Normal search should use relevance ordering' ),
			'exclusion-only search'      => array( '-condebug', false, 'Exclusion-only search should not use relevance ordering' ),
			'empty search'               => array( '', false, 'Empty search should not use relevance ordering' ),
			'multiple exclusion terms'   => array( '-foo+-bar', false, 'Multiple exclusion terms should not use relevance ordering' ),
			'mixed positive + exclusion' => array( 'shirt+-condebug', true, 'Mixed search with positive terms should use relevance ordering' ),
			'bare dash'                  => array( '-', false, 'Bare dash search should not use relevance ordering' ),
			'comma-separated mixed'      => array( '-foo,bar', true, 'Comma-separated search with positive terms should use relevance ordering' ),
		);
	}

	/**
	 * @testdox Ordering args: $description.
	 * @dataProvider data_provider_search_ordering
	 *
	 * @param string $search           The search query string.
	 * @param bool   $expect_relevance Whether relevance ordering is expected.
	 * @param string $description      Test case description.
	 */
	public function test_get_catalog_ordering_args_search_ordering( string $search, bool $expect_relevance, string $description ): void {
		$sut = new WC_Query();

		$this->go_to( '/?s=' . rawurlencode( $search ) . '&post_type=product' );

		$result = $sut->get_catalog_ordering_args();

		if ( $expect_relevance ) {
			$this->assertSame( 'relevance', $result['orderby'], $description );
		} else {
			$this->assertNotEquals( 'relevance', $result['orderby'], $description );
		}
	}

	/**
	 * @testdox Ordering args should respect the wp_query_search_exclusion_prefix filter.
	 */
	public function test_get_catalog_ordering_args_respects_custom_exclusion_prefix(): void {
		$sut = new WC_Query();

		$custom_prefix = static function () {
			return '!';
		};
		add_filter( 'wp_query_search_exclusion_prefix', $custom_prefix );

		$this->go_to( '/?s=!foo&post_type=product' );

		$result = $sut->get_catalog_ordering_args();

		remove_filter( 'wp_query_search_exclusion_prefix', $custom_prefix );

		$this->assertNotEquals( 'relevance', $result['orderby'], 'Exclusion-only search with custom prefix should not use relevance ordering' );
	}

	/**
	 * @testdox Sitewide search includes or excludes products according to their catalog visibility setting.
	 *
	 * @dataProvider visibility_search_provider
	 *
	 * @param string $visibility       The catalog visibility setting to test.
	 * @param bool   $should_be_found  Whether the product is expected to appear in search results.
	 * @param string $expected_message The expected assertion message.
	 */
	public function test_search_respects_product_visibility( string $visibility, bool $should_be_found, string $expected_message ) {
		// Create a baseline product that should always appear in search.
		$visible_product = WC_Helper_Product::create_simple_product();
		$visible_product->set_name( 'Search Visible Product' );
		$visible_product->set_catalog_visibility( 'visible' );
		$visible_product->save();

		// Create the product under test with the visibility provided by the data provider.
		$test_product = WC_Helper_Product::create_simple_product();
		$test_product->set_name( 'Search Tested Product' );
		$test_product->set_catalog_visibility( $visibility );
		$test_product->save();

		// Save the previous main query and prepare for a new one.
		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		// Set the query as the main query before running so pre_get_posts fires with WC_Query's handler.
		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query( array( 's' => 'Search' ) );
		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $visible_product->get_id(), $found_ids, 'Visible product should always appear in search results' );

		if ( $should_be_found ) {
			$this->assertContains( $test_product->get_id(), $found_ids, $expected_message );
		} else {
			$this->assertNotContains( $test_product->get_id(), $found_ids, $expected_message );
		}

		// Cleanup.
		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$visible_product->delete( true );
		$test_product->delete( true );
	}

	/**
	 * Data provider for visibility-based search tests.
	 *
	 * @return array
	 */
	public function visibility_search_provider(): array {
		return array(
			'catalog visibility (shop only)' => array( 'catalog', false, 'Product with catalog-only visibility should not appear in search results' ),
			'hidden visibility'              => array( 'hidden', false, 'Product with hidden visibility should not appear in search results' ),
			'search visibility'              => array( 'search', true, 'Product with search-only visibility should appear in search results' ),
		);
	}

	/**
	 * @testdox Sitewide search excludes hidden products while continuing to return regular posts.
	 */
	public function test_search_excludes_hidden_products_but_keeps_other_post_types() {
		$hidden_product = WC_Helper_Product::create_simple_product();
		$hidden_product->set_name( 'Search Hidden Companion Product' );
		$hidden_product->set_catalog_visibility( 'hidden' );
		$hidden_product->save();

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Search Regular Post',
				'post_content' => 'Body content referencing Search.',
			)
		);

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query( array( 's' => 'Search' ) );
		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $post_id, $found_ids, 'Regular posts should still appear in sitewide search results' );
		$this->assertNotContains( $hidden_product->get_id(), $found_ids, 'Hidden products should be filtered out of sitewide search results' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_delete_post( $post_id, true );
		$hidden_product->delete( true );
	}

	/**
	 * @testdox A tax_query set by another plugin or hook before WC_Query's pre_get_posts survives the visibility merge.
	 */
	public function test_search_preserves_existing_tax_query() {
		$existing_clause = array(
			'taxonomy' => 'category',
			'field'    => 'slug',
			'terms'    => array( 'uncategorized' ),
		);

		// Hook at priority 5 so it runs before WC_Query::pre_get_posts (default priority 10).
		$hook = function ( $q ) use ( $existing_clause ) {
			if ( $q->is_search() ) {
				$q->set( 'tax_query', array( $existing_clause ) );
			}
		};
		add_action( 'pre_get_posts', $hook, 5 );

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query( array( 's' => 'Search' ) );

		$tax_query = $query->get( 'tax_query' );
		$this->assertIsArray( $tax_query, 'Tax query should be an array after WC_Query merges its clause.' );
		$this->assertContains( $existing_clause, $tax_query, 'Pre-existing tax_query clause should survive the merge.' );

		$product_visibility_terms = wc_get_product_visibility_term_ids();
		$exclude_term_id          = (int) $product_visibility_terms['exclude-from-search'];
		$visibility_clause        = null;
		foreach ( $tax_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'product_visibility' === $clause['taxonomy'] ) {
				$visibility_clause = $clause;
				break;
			}
		}
		$this->assertNotNull( $visibility_clause, 'WC_Query should append the product_visibility exclusion clause to the existing tax_query.' );
		$this->assertSame( 'term_taxonomy_id', $visibility_clause['field'], 'Visibility clause should match by term_taxonomy_id.' );
		$this->assertSame( array( $exclude_term_id ), $visibility_clause['terms'], 'Visibility clause should target the exclude-from-search term.' );
		$this->assertSame( 'NOT IN', $visibility_clause['operator'], 'Visibility clause should use the NOT IN operator.' );

		remove_action( 'pre_get_posts', $hook, 5 );
		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox Product search by SKU returns matching products.
	 */
	public function test_product_search_finds_products_by_sku() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( 'Unique Product Name For SKU Test' );
		$product->set_sku( 'SKU-TEST-12345' );
		$product->save();

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query(
			array(
				's'         => 'SKU-TEST-12345',
				'post_type' => 'product',
			)
		);
		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $product->get_id(), $found_ids, 'Product should be found by its SKU in product search.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$product->delete( true );
	}

	/**
	 * @testdox Product search by name still works alongside SKU search.
	 */
	public function test_product_search_by_name_still_works() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( 'FindMeByName SearchTest' );
		$product->set_sku( 'UNIQUE-SKU-NAME-TEST' );
		$product->save();

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query(
			array(
				's'         => 'FindMeByName SearchTest',
				'post_type' => 'product',
			)
		);
		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $product->get_id(), $found_ids, 'Product should still be found by its name.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$product->delete( true );
	}

	/**
	 * @testdox SKU search is not added when wc_product_sku_enabled returns false.
	 */
	public function test_sku_search_not_added_when_disabled() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( 'SKU Disabled Test Product' );
		$product->set_sku( 'DISABLED-SKU-99999' );
		$product->save();

		add_filter( 'wc_product_sku_enabled', '__return_false' );

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$query->query(
			array(
				's'         => 'DISABLED-SKU-99999',
				'post_type' => 'product',
			)
		);
		$found_ids = wp_list_pluck( $query->posts, 'ID' );

		$this->assertNotContains( $product->get_id(), $found_ids, 'Product should NOT be found by SKU when SKU search is disabled.' );

		remove_filter( 'wc_product_sku_enabled', '__return_false' );
		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$product->delete( true );
	}

	/**
	 * @testdox add_product_sku_to_search correctly modifies the search clause.
	 */
	public function test_add_product_sku_to_search_modifies_clause() {
		$sut = new WC_Query();

		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$query->query( array( 's' => 'testsku' ) );

		$search = ' AND ((wp_posts.post_title LIKE \'%testsku%\') OR (wp_posts.post_excerpt LIKE \'%testsku%\') OR (wp_posts.post_content LIKE \'%testsku%\')) ';

		$result = $sut->add_product_sku_to_search( $search, $query );

		$this->assertStringContainsString( 'wc_product_meta_lookup.sku LIKE', $result, 'Search clause should include SKU match.' );
		$this->assertStringContainsString( 'OR', $result, 'Search clause should use OR to combine SKU with other conditions.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox add_product_sku_to_search returns unmodified clause for empty search.
	 */
	public function test_add_product_sku_to_search_returns_original_for_empty_search() {
		$sut = new WC_Query();

		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$search = ' AND ((wp_posts.post_title LIKE \'%test%\')) ';
		$result = $sut->add_product_sku_to_search( $search, $query );

		$this->assertSame( $search, $result, 'Empty search should return unmodified clause.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox product_search_post_join adds wc_product_meta_lookup JOIN.
	 */
	public function test_product_search_post_join_adds_lookup_table() {
		$sut  = new WC_Query();
		$join = 'original join clause';

		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;
		$query                 = new WP_Query();
		$wp_the_query          = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$result = $sut->product_search_post_join( $join, $query );

		$this->assertStringContainsString( 'wc_product_meta_lookup', $result, 'JOIN should include wc_product_meta_lookup table.' );
		$this->assertStringContainsString( 'LEFT JOIN', $result, 'Should use LEFT JOIN.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox product_search_post_join does not duplicate existing JOIN.
	 */
	public function test_product_search_post_join_no_duplicate() {
		$sut  = new WC_Query();
		$join = 'original LEFT JOIN wc_product_meta_lookup ON wp_posts.ID = wc_product_meta_lookup.product_id';

		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;
		$query                 = new WP_Query();
		$wp_the_query          = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$result = $sut->product_search_post_join( $join, $query );

		$this->assertSame( $join, $result, 'Existing JOIN should be returned unchanged.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox product_search_post_join does not modify non-main queries.
	 */
	public function test_product_search_post_join_skips_non_main_query() {
		$sut  = new WC_Query();
		$join = 'original join clause';

		// Query not set as main query.
		$query         = new WP_Query();
		$previous_main = $GLOBALS['wp_the_query'] ?? null;
		// Use an instance that is not main.
		$query->is_main_query = false;

		$result = $sut->product_search_post_join( $join, $query );

		$this->assertSame( $join, $result, 'Should not modify JOIN for non-main queries.' );
	}

	/**
	 * @testdox Exclusion search terms are not added as positive SKU matches.
	 */
	public function test_add_product_sku_to_search_skips_exclusion_terms() {
		$sut = new WC_Query();

		global $wp_the_query;
		$previous_wp_the_query = $wp_the_query;
		$query                 = new WP_Query();
		$wp_the_query          = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$query->query( array( 's' => '-foo' ) );

		$search = " AND ((wp_posts.post_title LIKE '%foo%')) ";
		$result = $sut->add_product_sku_to_search( $search, $query );

		// Negative-only search should produce no SKU clause.
		// In this case function returns search unchanged because SKUs are empty after filtering.
		$this->assertStringNotContainsString( 'wc_product_meta_lookup.sku LIKE', $result, 'Exclusion terms should not be added as positive SKU matches.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox product_query() registers SKU search filters for search queries.
	 *
	 * The Product Search block submits ?s=term&post_type=product, which makes
	 * is_post_type_archive( 'product' ) true and routes the query through
	 * product_query() rather than the non-archive branch. SKU registration must
	 * therefore live inside product_query().
	 */
	public function test_product_query_registers_sku_filters_on_search() {
		$sut = new WC_Query();

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query        = new WP_Query();
		$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$query->set( 's', 'some-sku-term' );
		$query->is_search = true;

		// Clear any pre-existing filters from a prior run.
		remove_filter( 'posts_search', array( $sut, 'add_product_sku_to_search' ) );
		remove_filter( 'posts_join', array( $sut, 'product_search_post_join' ) );

		$sut->product_query( $query );

		$this->assertNotFalse( has_filter( 'posts_search', array( $sut, 'add_product_sku_to_search' ) ), 'posts_search filter should be registered on search queries.' );
		$this->assertNotFalse( has_filter( 'posts_join', array( $sut, 'product_search_post_join' ) ), 'posts_join filter should be registered on search queries.' );

		// Cleanup.
		remove_filter( 'posts_search', array( $sut, 'add_product_sku_to_search' ) );
		remove_filter( 'posts_join', array( $sut, 'product_search_post_join' ) );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox product_query() does not register SKU filters for non-search queries.
	 */
	public function test_product_query_skips_sku_filters_on_non_search() {
		$sut = new WC_Query();

		global $wp_the_query, $wp_query;
		$previous_wp_the_query = $wp_the_query;
		$previous_wp_query     = $wp_query;

		$query            = new WP_Query();
		$wp_the_query     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query         = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$query->is_search = false;

		remove_filter( 'posts_search', array( $sut, 'add_product_sku_to_search' ) );
		remove_filter( 'posts_join', array( $sut, 'product_search_post_join' ) );

		$sut->product_query( $query );

		$this->assertFalse( has_filter( 'posts_search', array( $sut, 'add_product_sku_to_search' ) ), 'posts_search filter should not be registered for non-search queries.' );
		$this->assertFalse( has_filter( 'posts_join', array( $sut, 'product_search_post_join' ) ), 'posts_join filter should not be registered for non-search queries.' );

		$wp_the_query = $previous_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_query     = $previous_wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * @testdox maybe_prevent_redirect_for_sku_search() returns false when the SKU search flag is set.
	 */
	public function test_maybe_prevent_redirect_for_sku_search_returns_false_when_flag_set() {
		$this->set_sku_search_active( true );

		$result = WC_Query::maybe_prevent_redirect_for_sku_search( true );

		$this->assertFalse( $result, 'Redirect should be suppressed when SKU search is active.' );

		$this->set_sku_search_active( false );
	}

	/**
	 * @testdox maybe_prevent_redirect_for_sku_search() returns the original value when the SKU search flag is not set.
	 */
	public function test_maybe_prevent_redirect_for_sku_search_returns_true_by_default() {
		$this->set_sku_search_active( false );

		$result = WC_Query::maybe_prevent_redirect_for_sku_search( true );

		$this->assertTrue( $result, 'Redirect should be allowed when SKU search is not active.' );
	}

	/**
	 * @testdox add_product_sku_to_search() does not set the redirect flag when only exclusion terms are searched.
	 */
	public function test_sku_search_does_not_set_redirect_flag_when_no_positive_terms() {
		$sut = new WC_Query();
		$this->set_sku_search_active( false );

		$query            = new WP_Query();
		$query->is_search = true;
		$query->set( 's', '-foo' );
		$query->set( 'search_terms', array( '-foo' ) );

		$result = $sut->add_product_sku_to_search( ' AND ((wp_posts.post_title LIKE %s)) ', $query );

		$this->assertSame( ' AND ((wp_posts.post_title LIKE %s)) ', $result, 'Search clause should be unchanged when no positive SKU terms exist.' );
		$this->assertFalse( $this->get_sku_search_active(), 'Redirect flag should remain false when no positive SKU terms exist.' );
	}

	/**
	 * Reflection helper: read the private $sku_search_active static property.
	 *
	 * @return bool
	 */
	private function get_sku_search_active(): bool {
		$reflection = new ReflectionClass( WC_Query::class );
		$property   = $reflection->getProperty( 'sku_search_active' );
		$property->setAccessible( true );
		return (bool) $property->getValue();
	}

	/**
	 * Reflection helper: write the private $sku_search_active static property.
	 *
	 * @param bool $value Value to set.
	 */
	private function set_sku_search_active( bool $value ): void {
		$reflection = new ReflectionClass( WC_Query::class );
		$property   = $reflection->getProperty( 'sku_search_active' );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}
}
