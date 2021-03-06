<?php

require_once( __DIR__ . '/includes/prefix-filter.php');
require_once( __DIR__ . '/includes/fwc-meta-utilities.php');
require_once( __DIR__ . '/includes/fwc-post-votes.php');
require_once( __DIR__ . '/includes/fwc-post-partials.php');
require_once( __DIR__ . '/includes/fwc-post-previous-searches.php');
require_once( __DIR__ . '/includes/fwc-export.php');
require_once( __DIR__ . '/includes/fwc-migrate-data.php');
require_once( __DIR__ . '/includes/fwc-migrate-psql.php');
require_once( __DIR__ . '/includes/fwc-search-result-screenshot.php');

function fwc_after_setup_theme() {
	add_theme_support( 'html5', array( 'gallery', 'caption' ) );
	add_filter('wp_get_attachment_image_attributes', function($attr) {
		if (isset($attr['sizes'])) unset($attr['sizes']);
		if (isset($attr['srcset'])) unset($attr['srcset']);
		return $attr;
	}, PHP_INT_MAX);
	add_filter('wp_calculate_image_sizes', '__return_false', PHP_INT_MAX);
	add_filter('wp_calculate_image_srcset', '__return_false', PHP_INT_MAX);
	remove_filter('the_content', 'wp_make_content_images_responsive');
}
add_action( 'after_setup_theme', 'fwc_after_setup_theme' );

function fwc_register_menu() {
  register_nav_menu( 'header-menu', __( 'Header Menu' ) );
}
add_action( 'init', 'fwc_register_menu' );

////////////////////////////////////////////////////////////
//// Set up post voting & tagging. See fwc-post-votes.php.
////////////////////////////////////////////////////////////
add_action('wp_enqueue_scripts', 'fwc_post_vote_scripts');
add_action('wp_ajax_fwc_post_vote', 'fwc_post_vote');
add_action('wp_ajax_nopriv_fwc_post_vote', 'fwc_post_vote');

function fwc_post_meta() {
	$client = fwc_get_latest_meta('client');
	$client = get_post_meta(get_the_ID(), 'search_client_name')[0];
	echo 'Search by '.esc_html($client).' on <a href="'.get_the_permalink().'" class="permalink">'.fwc_format_date(fwc_get_latest_meta('timestamp')).'</a>';
}

/////////////////////////////////////////////////
//// Submit new posts.
/////////////////////////////////////////////////

function fwc_submit_images() {
	fwc_enable_cors();

	if (!defined('FWC_SHARED_SECRET')) {
		die('No FWC_SHARED_SECRET defined internally');
	}

	if (empty($_POST['secret']) || $_POST['secret'] != FWC_SHARED_SECRET) {
		header('Content-Type: application/json');
		echo json_encode(array(
			'ok' => 0,
			'error' => 'Shared secret did not match'
		));
		exit;
	}

	$timestamp = round($_POST['timestamp'] / 1000);

	// Serialize data from image submission request into uniform schema
	$data = array(
		// 'data_migration_nearest_neighbor_images' => NULL, // Legacy field present on some migrated posts
		// 'data_migration_post_id_original' => NULL, // Legacy field present on migrated posts
		// 'data_migration_unflattened' => NULL, // Legacy field present on some migrated posts
		// 'search_term_popularity' => NULL, // Legacy field present on some migrated posts
		'data_migration_schema_original' => 2, // Which schema version was used to save search
		'search_client_name' => $_POST['client'],
		'search_engine_initial' => $_POST['search_engine'],
		'search_location' => $_POST['location'] ?: 'unknown',
		'search_term_initial' => $_POST['query'],
		'search_term_language_initial_code' => $_POST['lang_from'],
		'search_term_language_initial_name' => $_POST['lang_name'],
		'search_term_language_initial_confidence' => $_POST['lang_confidence'],
		'search_term_language_initial_alternate' => $_POST['lang_alternate'],
		'search_term_translation' => $_POST['translated'],
		'search_term_status_banned' => ($_POST['banned'] === 'true' || $_POST['banned'] === true) ? 1 : 0,
		'search_term_status_sensitive' => ($_POST['sensitive'] === 'true' || $_POST['sensitive'] === true) ? 1 : 0,
		'timestamp' => $timestamp, // 10-digit Unix timestamp
		'copyright_takedown' => NULL,
		'votes_censored' => 0,
		'votes_uncensored' => 0,
		'votes_bad_translation' => 0,
		'votes_good_translation' => 0,
		'votes_lost_in_translation' => 0,
		'votes_bad_result' => 0,
		'votes_nsfw' => 0,
	);
	// TODO Change/eliminate these field names for clarity e.g.
	// consider dropping data_migration_unflattened and related fields
	// data_migration_schema_original => data_schema_initial
	// search_term_language_initial_code => search_term_initial_language_code
	// search_term_language_initial_name => search_term_initial_language_name
	// search_term_language_initial_confidence => search_term_initial_language_confidence
	// search_term_language_initial_alternate => search_term_initial_language_alternate

	// Create WP post as a draft
	$post_date_gmt = date('Y-m-d H:i:s', $data['timestamp']);
	$post_slug = implode('-', explode(' ', $data['search_term_initial'])).'-'.$data['timestamp'];
	$post_data = array(
		'post_date' => $post_date_gmt,
		'post_date_gmt' => $post_date_gmt,
		'post_excerpt' => $data['search_term_translation'],
		'post_name' => $post_slug, // Uniqueish identifier
		'post_status' => 'draft',
		'post_title' => $data['search_term_initial'],
		'post_type' => 'search-result',
	);
	$post_id = wp_insert_post($post_data);

	if (!empty($post_id)) {
		// Save image attachments and return arrays with original URLs
		// TODO Refactor image management code to use parameter/key for search engine, not separate fields
		$images_google = json_decode(stripslashes($_POST['google_images']), 'as hash');
		$images_baidu = json_decode(stripslashes($_POST['baidu_images']), 'as hash');

		$attachments_google = fwc_save_images($post_id, $images_google, 'google-'.$timestamp);
		$attachments_baidu = fwc_save_images($post_id, $images_baidu, 'baidu-'.$timestamp);

		$gallery_google = implode(',', $attachments_google);
		$gallery_baidu = implode(',', $attachments_baidu);

		$data['images_google'] = array_map(function ($key) use ($attachments_google, $images_google) {
			return array(
				'original_href' => $images_google[$key]['href'],
				'attachment_post_id' => $attachments_google[$key],
			);
		}, array_keys($images_google));
		$data['images_baidu'] = array_map(function ($key) use ($attachments_baidu, $images_baidu) {
			return array(
				'original_href' => $images_baidu[$key]['href'],
				'attachment_post_id' => $attachments_baidu[$key],
			);
		}, array_keys($images_baidu));

		// Save serialized data to WP post
		foreach ($data as $meta_key => $meta_value) {
			update_post_meta($post_id, $meta_key, $meta_value);
		}

		// Add prefixed tags used to easily retrieve posts matching certain data fields
		$tags = [];
		if ($data['search_term_status_banned']) {
			$tags[] = 'has_search_term_status_banned';
		}
		if ($data['search_term_status_sensitive']) {
			$tags[] = 'has_search_term_status_sensitive';
		}
		$tags[] = 'has_search_location_'.$data['search_location'];
		$tags[] = 'has_search_year_'.date('Y', $data['timestamp']);
		if ($data['search_term_language_initial_code']) {
			$tags[] = 'has_search_term_language_initial_code_'.$data['search_term_language_initial_code'];
		}
		$tags = implode(',', $tags);
		wp_set_post_terms($post_id, $tags, 'post_tag', false /* do not append */);

		// Save image attachment IDs to post body (legacy/convenience), publish post, return permalink
		$data_update = array(
			'ID' => $post_id,
			'post_content' => "Google\n[gallery ids=\"".$gallery_google."\"]\nBaidu\n[gallery ids=\"".$gallery_baidu."\"]",
			'edit_date' => false,
			'post_status' => 'publish'
		);

		wp_update_post($data_update);
		$permalink = get_permalink($post_id);

		header('Content-Type: application/json');
		echo json_encode(array(
			'ok' => 1,
			'permalink' => $permalink,
			'title' => 'We’ve saved results for “'.$data['search_term_initial'].'” to the FIREWALL search library',
			'message' => 'CLICK HERE to tell us what you think of the results'
		));
	} else {
		header('Content-Type: application/json');
		echo json_encode(array(
			'ok' => 0,
			'permalink' => '',
			'title' => 'Error saving results for “'.$data['search_term_initial'].'” to the FIREWALL search library',
			'message' => ''
		));
	}

	exit;
}

add_action('wp_ajax_fwc_submit_images', 'fwc_submit_images');
add_action('wp_ajax_nopriv_fwc_submit_images', 'fwc_submit_images');

function fwc_import_post($row) {
	$verbose = ! empty($_GET['verbose']);
	$slug = sanitize_title("$row->query");

	if ($verbose) {
		echo "Query: ".$slug."</br>";
	}

	$post = get_page_by_path($slug, OBJECT, 'post');

	if ($post) {
		$post_id = $post->ID;
		if ($verbose) {
			echo "Post ID: ".$post_id."</br>";
			echo "Post already exists. Updating post with new data.</br>";
		}
		fwc_update_post_content($post_id, $row);
	} else {
		$title = "$row->query";
		$post_id = wp_insert_post(array(
			'post_title' => $title,
			'post_name' => $slug,
			'post_status' => 'draft'
		));
		if ($verbose) {
			echo "Post ID: ".$post_id."</br>";
			echo "New post. Adding post with current data.</br>";
		}

		if (!empty($post_id)) {
			fwc_initialize_post_content($post_id, $row);
		}
	}
	return $post_id;
}

function fwc_initialize_post_content($post_id, $row) {
	fwc_initialize_post_metadata($post_id, $row);
	fwc_build_post_content($post_id, $row);
}

function fwc_initialize_post_metadata($post_id, $row) {
	fwc_update_post_metadata($post_id, $row);

	add_post_meta( $post_id, 'new_template_style', 1, true);

	$zero_votes = array(
		'censored_votes',
		'uncensored_votes',
		'maybe_censored_votes',
		'bad_translation_votes',
		'good_translation_votes',
		'lost_in_translation_votes',
		'firewall_bug_votes',
		'nsfw_votes',
		'bad_result_votes',
		'slow_search_votes',
		'no_result_votes'
	);

	foreach ($zero_votes as $key) {
		add_post_meta( $post_id, $key, 0, true);
	}
}

function fwc_build_post_content($post_id, $row) {
	$google_images = json_decode($row->google_images, 'as hash');
	$baidu_images = json_decode($row->baidu_images, 'as hash');

	$google_images_html = fwc_build_image_set($post_id, $row, $google_images, 'google');
	$baidu_images_html = fwc_build_image_set($post_id, $row, $baidu_images, 'baidu');

	$post_content = $google_images_html . $baidu_images_html;

	$timestamp = round($row->timestamp / 1000);
	$post_date = date('Y-m-d H:i:s', $timestamp - (5 * 60 * 60));
	$post_date_gmt = date('Y-m-d H:i:s', $timestamp);

	$post_data = array(
		'ID' => $post_id,
		'post_content' => $post_content,
		'post_date' => $post_date,
		'post_date_gmt' => $post_date_gmt,
		'edit_date' => true,
		'post_status' => 'publish'
	);

	wp_update_post($post_data);
}

function fwc_build_image_set($post_id, $row, $images, $label) {
	$verbose = ! empty($_GET['verbose']);
	if ($verbose) {
		echo "Building ".$label." image set</br>";
	}
	if ($label == $row->search_engine) {
		$term = $row->query;
	} else {
		$term = $row->translation;
	}

	$urls = array_keys($images);
	if ($verbose) {
		echo "Term: ".$term."</br>";
		echo "URLS: ".implode(', ', $urls)."</br>";
	}

	$timestamp = round($row->timestamp / 1000);

	$attachments = fwc_save_images($post_id, $images, "$label-$timestamp");

	$link = get_the_permalink($post_id);

	$heading = "<h3 class=\"query-label\">". ucwords($label) . ": <strong><a href=\"" . esc_url($link) . "\">$term</a></strong></h3>";
	$ids = implode(',', $attachments);

	$image_set = "$heading\n[gallery ids=\"$ids\" link=\"none\"]\n\n";
	return $image_set;
}

/////////////////////////////////////////////////
//// Update posts.
/////////////////////////////////////////////////

function fwc_update_post_metadata($post_id, $row) {
	$timestamp = round($row->timestamp / 1000);
	add_post_meta($post_id, 'timestamp', $timestamp, false);

	$location = 'Ann Arbor';

	$metadata = array(
		'client' => $row->client,
		'translation' => $row->translation,
		'search_engine' => $row->search_engine,
		'google_images' => $row->google_images,
		'baidu_images' => $row->baidu_images,
		'search_language' => $row->lang_from,
		'search_language_confidence' => $row->lang_confidence,
		'search_language_alternate' => $row->lang_alternate,
		'search_language_name' => $row->lang_name,
		'banned' => $row->banned,
		'sensitive' => $row->sensitive,
		'location' => $location,
	);

  fwc_add_post_timestamped_meta($post_id, $metadata, $timestamp);
  fwc_set_search_language($post_id, $row->lang_name);
  fwc_set_search_engine($post_id, $row->search_engine);
  fwc_set_location($post_id, $location);
  fwc_set_banned($post_id, $row->banned);
  fwc_set_sensitive($post_id, $row->sensitive);
}

function fwc_add_post_timestamped_meta($post_id, $metadata, $timestamp) {
	foreach ($metadata as $meta_key => $data) {
		add_post_meta( $post_id, $meta_key, array( $timestamp => $data ), false );
	}
}

function fwc_update_post_content($post_id, $row) {
	fwc_update_post_metadata($post_id, $row);
	fwc_build_post_content($post_id, $row);
}

function fwc_update_popularity($post_id) {
	$popularity = get_post_meta($post_id, 'popularity', true);
	if (!$popularity) {
		update_post_meta($post_id, 'popularity', 1);
	} else {
		$popularity = intval($popularity) + 1;
		update_post_meta($post_id, 'popularity', $popularity);
	}
}

// https://localhost:4747/wp-admin/admin-ajax.php?action=fwc_migrate_categories&page=1

function fwc_migrate_categories() {
	$page = $_GET['page'];
	if (! $page) {
		$page = 1;
	}

	// This is how we decide which categories get put into which new taxonomies
	$migration = array(
		'badtranslation' => array( // <--- category
			'translation_status' => 'bad-translation' // <---- new taxonomy + value
		),
		'good' => array(
			'translation_status' => 'good-translation'
		),
		'lost' => array(
			'translation_status' => 'lost-in-translation'
		),
		'nyc' => array(
			'locations' => 'nyc'
		)
	);

	echo "<pre>";
	echo "Page $page\n";
	$posts = get_posts(array(
		'paged' => $page,
		'posts_per_page' => 500
	));
	$object_ids = array();
	foreach ($posts as $post) {
		echo "$post->post_title\n";
		$categories = wp_get_object_terms($post->ID, 'category');
		foreach ($categories as $cat) {
			if ($migration[$cat->slug]) {
				foreach ($migration[$cat->slug] as $tax => $value) {
					echo "  $tax: $value\n";
					wp_add_object_terms($post->ID, $value, $tax);
					wp_remove_object_terms($post->ID, $cat->slug, 'category');
				}
			}
		}
	}
	if (! empty($posts)) {
		$page++;
		echo "</pre>";
		// It's cheesy but it works...
		echo "<script>window.location = '/wp-admin/admin-ajax.php?action=fwc_migrate_categories&page=$page';</script>";
	} else {
		echo "All done";
		echo "</pre>";
	}
	exit;
}
add_action('wp_ajax_fwc_migrate_categories', 'fwc_migrate_categories');

/////////////////////////////////////////////////
//// Manage WP Media Library.
/////////////////////////////////////////////////

function fwc_save_images($parent_id, $images, $prefix) {
	$verbose = ! empty($_GET['verbose']);
	$image_ids = array();
	$upload_dir = wp_upload_dir();
	$num = 0;
	foreach ($images as $image) {
		$href = $image['href'];
		$src = $image['src'];

		if ($verbose) {
			echo "$href: ";
		}
		if (substr($src, 0, 5) == 'data:') {
			if ($verbose) {
				echo "data URI<br>";
			}
			$image = fwc_derive_data_uri($src);
		} else {
			if ($verbose) {
				echo "download<br>";
			}
			$image = fwc_download_image($src);
		}

		if (is_array($image)) {
			extract($image);

			// Now we have:
			// $binary_data
			// $content_type

			$num++;
			$image_num = $num;
			if ($image_num < 10) {
				$image_num = '0' . $image_num;
			}
			if ($content_type == 'image/jpeg') {
				$ext = 'jpg';
			} else if ($content_type == 'image/gif') {
				$ext = 'gif';
			} else if ($content_type == 'image/png') {
				$ext = 'png';
			} else {
				if ($verbose) {
					echo "Unexpected content-type: $content_type<br>";
				}
				continue;
			}
			$date = current_time('d');
			$dir = $upload_dir['path'] . "/$date/$parent_id";
			if (! file_exists($dir)) {
				wp_mkdir_p($dir);
			}
			$path = "$dir/$prefix-$image_num.$ext";
			file_put_contents($path, $binary_data);
			if ($verbose) {
				echo "Saved: $path<br>";
			}
			$image_id = fwc_attach_image($parent_id, $path, $href);
			$image_ids[] = $image_id;
		}
	}
	return $image_ids;
}

function fwc_derive_data_uri($src) {
	// Example $data:
	// data:image/jpeg;base64,[base64 data]
	// data:image/jpeg;charset=utf8;base64,[base64 data]

	// Remove the "data:" prefix
	$data = substr($src, 5);

	// Derive the type
	$semicolon_pos = strpos($data, ';');
	$content_type = substr($data, 0, $semicolon_pos);

	// Remove the header
	$comma_pos = strpos($data, ',');
	$base64_data = substr($data, $comma_pos);

	$binary_data = base64_decode($base64_data);

	if ($binary_data) {
		return array(
			'binary_data' => $binary_data,
			'content_type' => $content_type
		);
	}
	return null;
}

function fwc_download_image($src) {
	// $url = urldecode($url);
	$verbose = ! empty($_GET['verbose']);
	if ($verbose) {
		echo "downloading $src: ";
	}
	$response = wp_remote_get($src, array(
		'timeout' => '30',
		'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:44.0) Gecko/20100101 Firefox/44.0'
	));
	$status = wp_remote_retrieve_response_code($response);
	if ($verbose) {
		echo $status . "<br>";
	}
	if ($status == 200) {
		$body = wp_remote_retrieve_body($response);
		return array(
			'binary_data' => $body,
			'content_type' => $response['headers']['content-type']
		);
	}
	return null;
}

function fwc_attach_image($parent_id, $path, $href) {
	$filetype = wp_check_filetype(basename( $path ), null);
	$wp_upload_dir = wp_upload_dir();
	$attachment = array(
		'guid'           => $wp_upload_dir['url'] . '/' . basename( $path ),
		'post_mime_type' => $filetype['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $path ) ),
		'post_content'   => $href,
		'post_status'    => 'inherit'
	);
	$attach_id = wp_insert_attachment( $attachment, $path, $parent_id );
	require_once( ABSPATH . 'wp-admin/includes/image.php' );
	$attach_data = wp_generate_attachment_metadata( $attach_id, $path );
	wp_update_attachment_metadata( $attach_id, $attach_data );
	return $attach_id;
}

function fwc_intermediate_image_sizes($sizes) {
	// Causes only thumbnail and full size to be saved for any image attachment
	// upload. Originally only ran when FWC_IMPORTING_IMAGES defined, but makes
	// sense for all situations.
	return array(
		'thumbnail'
	);
}

add_filter('intermediate_image_sizes', 'fwc_intermediate_image_sizes');

/////////////////////////////////////////////////
//// Utilities
/////////////////////////////////////////////////

function fwc_test_post_data() {
	$row = (object) array(
		'timestamp' => 1494457634573,
		'search_engine' => 'google',
		'client' => 'Rachel',
		'query' => 'resist',
		'translation' => '抗',
		'google_images' => '["https%3A%2F%2Fi.kinja-img.com%2Fgawker-media%2Fimage%2Fupload%2Fs--41FDizDL--%2Fc_scale%2Cfl_progressive%2Cq_80%2Cw_800%2Focib1pq0fvtrducbxl7d.png","http%3A%2F%2Fnoorimages.com%2Fwp-content%2Fuploads%2F2017%2F02%2FResist-COVER-584x389.jpg","https%3A%2F%2Fsecure3.convio.net%2Fgpeace%2Fimages%2Fcontent%2Fpagebuilder%2FBanner_870x215.jpeg","https%3A%2F%2Fpbs.twimg.com%2Fprofile_images%2F691718142842818560%2FM4uF-40W.jpg","http%3A%2F%2Fmurverse.com%2Fwp-content%2Fuploads%2F2017%2F02%2Fdevelopers-will-resist.gif","https%3A%2F%2Factionnetwork.org%2Fuser_files%2Fuser_files%2F000%2F010%2F929%2Foriginal%2Fresisttrump_torch_s.png","https%3A%2F%2Ffrontierpartisans.com%2Fwp-content%2Fuploads%2F2017%2F03%2Fresist.jpg","http%3A%2F%2Fjannaldredgeclanton.com%2Fblog%2Fwp-content%2Fuploads%2F2017%2F02%2Fresist_together.jpeg","http%3A%2F%2Fi299.photobucket.com%2Falbums%2Fmm295%2Fnateblackwood%2Fresist-4.gif","http%3A%2F%2Fi3.cpcache.com%2Fproduct_zoom%2F2044095010%2Fresist_womens_light_tshirt.jpg%3Fcolor%3DLightPink%26c%3Dfalse","http%3A%2F%2Fwww.resistsubmission.com%2Fuploads%2F1%2F2%2F5%2F6%2F12564774%2F1479833405.png","https%3A%2F%2Fcdn-images-1.medium.com%2Fmax%2F800%2F1*wb21Tt9cJpHJsvIg4uPl9A.png","http%3A%2F%2Fresist.org%2Fsites%2Fdefault%2Ffiles%2Fstyles%2Fresponsive_large__normal%2Fpublic%2Fresist_logo_about.png%3Fitok%3DwtOJjWZl","http%3A%2F%2Ftrailmix.cc%2Fhome%2Fwp-content%2Fuploads%2F2016%2F11%2Fresist-t-shirts-men-s-premium-t-shirt.jpg","https%3A%2F%2Fresistmedia.org%2Fwp-content%2Fuploads%2F2016%2F10%2FRESIST-Logo-Large.png","http%3A%2F%2Fwww.configuringlight.org%2Fwp-content%2Fuploads%2FProject-Resist-9-of-37.jpg","http%3A%2F%2Fd3n8a8pro7vhmx.cloudfront.net%2Fshowingupforracialjustice%2Fpages%2F289%2Fmeta_images%2Foriginal%2FRESIST.jpg%3F1449689832","http%3A%2F%2Fwww.greenpeace.org%2Fusa%2Fwp-content%2Fuploads%2F2017%2F01%2FRESIST_digital_1200x1200_8.png","https%3A%2F%2Fsecure.meetupstatic.com%2Fs%2Fimg%2F44714141242236135880%2Fpro%2Fresist%2Fresist-emoji-site-gif.gif","http%3A%2F%2Fi3.cpcache.com%2Fproduct%2F2044406379%2Fkeep_calm_and_resist_button.jpg"]',
		'baidu_images' => '["https://ss0.bdstatic.com/70cFuHSh_Q1YnxGkpoWK1HF6hhy/it/u=1661433408,841215226&fm=23&gp=0.jpg","https://ss3.bdstatic.com/70cFv8Sh_Q1YnxGkpoWK1HF6hhy/it/u=397247676,4141938607&fm=23&gp=0.jpg","https://ss3.bdstatic.com/70cFv8Sh_Q1YnxGkpoWK1HF6hhy/it/u=3432971353,2951899866&fm=23&gp=0.jpg","https://ss2.bdstatic.com/70cFvnSh_Q1YnxGkpoWK1HF6hhy/it/u=1381996996,2638262872&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFuXSh_Q1YnxGkpoWK1HF6hhy/it/u=3953418884,2574051217&fm=23&gp=0.jpg","https://ss0.bdstatic.com/70cFvHSh_Q1YnxGkpoWK1HF6hhy/it/u=2567270588,2987941832&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFuXSh_Q1YnxGkpoWK1HF6hhy/it/u=4289996477,835387402&fm=23&gp=0.jpg","https://ss0.bdstatic.com/70cFuHSh_Q1YnxGkpoWK1HF6hhy/it/u=1049604512,740526578&fm=23&gp=0.jpg","https://ss0.bdstatic.com/70cFvHSh_Q1YnxGkpoWK1HF6hhy/it/u=3887855297,599606009&fm=23&gp=0.jpg","https://ss3.bdstatic.com/70cFv8Sh_Q1YnxGkpoWK1HF6hhy/it/u=2627347408,4170062475&fm=23&gp=0.jpg","https://ss2.bdstatic.com/70cFvnSh_Q1YnxGkpoWK1HF6hhy/it/u=2977936469,3441322417&fm=23&gp=0.jpg","https://ss3.bdstatic.com/70cFv8Sh_Q1YnxGkpoWK1HF6hhy/it/u=2043553209,347159970&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFuXSh_Q1YnxGkpoWK1HF6hhy/it/u=2002118507,2942484244&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFuXSh_Q1YnxGkpoWK1HF6hhy/it/u=144345291,2844992582&fm=23&gp=0.jpg","https://ss2.bdstatic.com/70cFvnSh_Q1YnxGkpoWK1HF6hhy/it/u=3830985130,4095017312&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFvXSh_Q1YnxGkpoWK1HF6hhy/it/u=2749677791,1561546566&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFuXSh_Q1YnxGkpoWK1HF6hhy/it/u=1154095850,504966351&fm=23&gp=0.jpg","https://ss1.bdstatic.com/70cFvXSh_Q1YnxGkpoWK1HF6hhy/it/u=1388251949,3163270590&fm=23&gp=0.jpg"]',
		'lang_from' => 'en',
		'lang_confidence' => '1',
		'lang_alternate' => '',
		'lang_name' => 'English',
	);
	return $row;
}

function fwc_enable_cors() {
	header('x-test: 1');
	header('Access-Control-Allow-Origin: *');
}

add_action('wp_headers', 'fwc_enable_cors');

/////////////////////////////////////////////////
//// Imports images from CSV file. ////
/////////////////////////////////////////////////
function fwc_import_images() {
	echo '<pre>';
	set_time_limit(0);
	define('FWC_IMPORTING_IMAGES', 1);
	$index = 0;
	if (!empty($_GET['index'])) {
		$index = $_GET['index'];
	}
	$dir = get_stylesheet_directory_uri();
	$csv = new CSV_File("$dir/images.csv");
	$curr = 0;
	while ($row = $csv->next_row($verbose)) {
		echo "$curr\n";

		// TODO: Edit below to allow empty image sets.
		if (empty($row) ||
		    empty($row->timestamp) ||
		    empty($row->query) ||
		    empty($row->translated) ||
		    empty($row->google_images) ||
		    empty($row->baidu_images)) {
			echo "Skipping $curr\n";
			continue;
		}

		$verbose = false; //($curr == 3095);
		if ($curr == $index) {
			if (!empty($_GET['import'])) {
				echo "Importing $row->query / $row->translated<br><br>";
				fwc_import_post($row);
				echo "Done.<br><br>";
				if (!empty($_GET['continue'])) {
					$date_only = (empty($_GET['date_only'])) ? '' : '&date_only=1';
					$next = $index + 1;
					$next_url = "?action=import_images&index=$next&import=1&continue=1$date_only";
					echo "<script>window.location = '$next_url';</script>";
				}
			} else {
				// TODO: Revise to manage new post data structure.
				echo "Google: $row->query<br><br>";
				$gi = json_decode($row->google_images);
				foreach ($gi as $src) {
					echo "<img src=\"$src\" style=\"height: 100px; width: auto;\">";
				}
				echo "<br><br>Baidu: $row->baidu_query<br><br>";
				$bi = json_decode($row->baidu_images);
				foreach ($bi as $src) {
					echo "<img src=\"$src\" style=\"height: 100px; width: auto;\">";
				}
				echo "<br><br>";
				echo "<a href=\"?action=import_images&amp;index=$index&amp;import=1\">import</a> | ";
				echo "<a href=\"?action=import_images&amp;index=$index&amp;import=1&amp;continue=1\">import and continue</a><br><br>";
			}
			if ($index > 0) {
				$prev = $index - 1;
				echo "<a href=\"?action=import_images&amp;index=$prev\">prev</a> | ";
			}
			$next = $index + 1;
			echo "<a href=\"?action=import_images&amp;index=$next\">next</a>";
			break;
		}
		$curr++;
	}
	exit;
}
add_action('wp_ajax_import_images', 'fwc_import_images');

class CSV_File {
	function __construct($path) {
		$this->path = $path;
		$this->fh = fopen($path, 'r');
		$this->headings = fgetcsv($this->fh);
	}

	function next_row($verbose = false) {
		if ($verbose)
			echo "before get\n";
		$row = fgetcsv($this->fh);
		if ($verbose)
			echo "after get\n";
		if (empty($row)) {
			if ($verbose)
				echo "returning null\n";
			return null;
		}
		$labeled = array();
		foreach ($row as $index => $value) {
			$key = $this->headings[$index];
			$labeled[$key] = $value;
		}
		if ($verbose)
			echo "returning labeled\n";
		return (object) $labeled;
	}
}

function create_event_post() {
	$args = array(
		'labels' => array(
			 'name' => __('Events'),
			 'singular_name' => __('Event'),
			 'menu_name' => __('Events'),
			 'parent_item' => __('Parent Event'),
			 'parent_item_colon' => __('Parent Event:'),
			 'all_items' => __('All Events'),
			 'view_item' => __('View Event'),
			 'add_new_item' => __('Add New Event'),
			 'add_new' => __('Add New'),
			 'edit_item' => __('Edit Event'),
			 'update_item' => __('Update Event'),
			 'search_items' => __('Search Events'),
			 'not_found' => __('Not Found'),
			 'not_found_in_trash' => __('Not Found in Trash'),
		),
		'public' => true,
		'show_ui' => true,
		'capability_type' => 'post',
		'hierarchical' => false,
		'rewrite' => array(
			'slug' => 'events',
			'with_front' => false,
		),
		'query_var' => true,
		'taxonomies' => array('event-category'),
		'menu_icon' => 'dashicons-calendar-alt',
		'supports' => array(
			'author',
			// 'comments',
			'custom-fields',
			'editor',
			// 'excerpt',
			// 'page-attributes',
			// 'post-formats',
			'revisions',
			// 'thumbnail',
			'title',
			// 'trackbacks',
		),
	);
	register_post_type('event', $args);
	flush_rewrite_rules(); // TODO Unnecessary?
}
add_action('init', 'create_event_post');

function create_event_post_category() {
	register_taxonomy(
		'event-category',
		'event',
		array(
			'hierarchical' => true,
			'labels' => array(
				'name' => __('Event Categories'),
				'singular_name' => __('Event Category'),
				'search_items' =>  __('Search Event Categories'),
				'all_items' => __('All Event Categories'),
				'parent_item' => __('Parent Event Category'),
				'parent_item_colon' => __('Parent Event Category:'),
				'edit_item' => __('Edit Event Category'),
				'update_item' => __('Update Event Category'),
				'add_new_item' => __('Add New Event Category'),
				'new_item_name' => __('New Event Category Name'),
				'menu_name' => __('Event Categories'),
			),
			'show_ui' => true,
			'show_admin_column' => true,
			'query_var' => true,
			'rewrite' => array(
				'slug' => 'event-category',
				'with_front' => false,
			),
		)
	);
}
add_action('init', 'create_event_post_category');

// Don't show "Add Media" button for event posts, to force use of "Media Gallery" field
add_filter('wp_editor_settings', function ($settings) {
	$current_screen = get_current_screen();

	if ($current_screen->post_type == 'event') {
		$settings['media_buttons'] = false;
	}
	return $settings;
});

function create_search_result_post() {
	$args = array(
		// 'capabilities' => array(), // Cf. http://justintadlock.com/archives/2013/09/13/register-post-type-cheat-sheet
		'capability_type' => 'post',
		'exclude_from_search' => false,
		'has_archive' => false,
		'hierarchical' => false,
		'labels' => array(
			 'add_new' => __('Add New'),
			 'add_new_item' => __('Add New Search Result'),
			 'all_items' => __('All Search Results'),
			 'edit_item' => __('Edit Search Result'),
			 'menu_name' => __('Search Results'),
			 'name' => __('Search Results'),
			 'not_found' => __('Not Found'),
			 'not_found_in_trash' => __('Not Found in Trash'),
			 'parent_item' => __('Parent Search Result'),
			 'parent_item_colon' => __('Parent Search Result:'),
			 'search_items' => __('Search Search Results'),
			 'singular_name' => __('Search Result'),
			 'update_item' => __('Update Search Result'),
			 'view_item' => __('View Search Result'),
		),
		'menu_icon' => 'dashicons-backup',
		'public' => true,
		'publicly_queryable' => true,
		'query_var' => true,
		'rewrite' => array(
			'slug' => 'archive',
			'with_front' => false,
		),
		'show_in_admin_bar' => false,
		'show_in_rest' => true,
		'show_in_rest' => true,
		'show_in_menu' => true,
		'show_ui' => true,
		'supports' => array(
			// 'author',
			// 'comments',
			'custom-fields',
			'editor',
			'excerpt',
			// 'page-attributes',
			// 'post-formats',
			// 'revisions',
			// 'thumbnail',
			'title',
			// 'trackbacks',
		),
		'taxonomies' => array('post_tag'),
	);
	register_post_type('search-result', $args);
	flush_rewrite_rules();
}
add_action('init', 'create_search_result_post');

add_action('rest_api_init', function () {
	register_rest_field('search-result', 'galleries', array(
		'get_callback' => function ($post) {
			return get_post_galleries($post['id'], false);
		},
		'schema' => array(
			'description' => __('Galleries'),
			'type' => 'array'
		),
	));
	register_rest_field('search-result', 'tags', array(
		'get_callback' => function ($post) {
			return get_the_tags($post['id']);
		},
		'schema' => array(
			'description' => __('Tags'),
			'type' => 'array'
		),
	));
});