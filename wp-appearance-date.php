<?php
/*
Plugin Name: Appearance Date
Plugin URI: http://austinmatzko.com/wordpress-plugins/wp-appearance-date/
Description: Show a post on a date distinct from its publish date.
Author: Austin Matzko
Author URI: http://austinmatzko.com
Version: 1.0
*/

class Filosofo_Appearance_Date_Factory {

	public function __construct()
	{
		$query = new Filosofo_Appearance_Date_Query_Handler; 
		if ( is_admin() ) {
			$admin = new Filosofo_Appearance_Date_Admin; 
			$admin->query = $query;
			add_action('admin_head', array(&$admin, 'print_style'));
			add_action('admin_init', array(&$admin, 'event_admin_init'));
			add_action('post_submitbox_misc_actions', array(&$admin, 'print_appearance_date_chooser'));
			add_action('wp_insert_post', array(&$admin, 'event_wp_insert_post'), 10, 2);
		} else {
			$installed = (bool) get_option('_filosofo_ap_date_installed');
			// check to make sure the table exists for this site's posts before joining
			if ( $installed ) {
				add_filter('posts_fields', array(&$query, 'filter_posts_fields'));	
				add_filter('posts_join', array(&$query, 'filter_posts_join'));	
				add_filter('posts_results', array(&$query, 'filter_posts_results'));	
				add_filter('posts_where', array(&$query, 'filter_posts_where'));	
				add_filter('the_posts', array(&$query, 'filter_the_posts'));
			}
		}

		add_action('init', array(&$query, 'event_init'));
	}

}


class Filosofo_Appearance_Date_Query_Handler {

	private $_appearance_table_name = 'object_appearance_dates';
	private $_posts_save;

	public function __construct()
	{
		$this->_posts_save = array();
	}

	/**
	 * Determine whether the appearance table exists.
	 *
	 * @param string $prefix Optional. The prefix of the site in question. If empty uses the current site's prefix.
	 * @return bool Whether the appearance table exists for the given site.
	 */
	private function _appearance_table_exists($prefix = '')
	{
		global $wpdb;
		if ( ! empty( $prefix ) ) {
			$wpdb->set_prefix($prefix);
		}

		$tables = (array) $wpdb->get_col('SHOW TABLES;');
		return in_array($wpdb->prefix . $this->_appearance_table_name, $tables);
	}

	/**
	 * Install the appearance date table, if it doesn't exist.
	 *
	 * @param string $prefix Optional. The prefix of the site in question. If empty uses the current site's prefix.
	 * @return bool Whether the installation was successful.
	 */
	private function _install_appearance_table($prefix = '')
	{
		global $wpdb;
		if ( ! empty( $prefix ) ) {
			$wpdb->set_prefix($prefix);
		}
		
		$app_table = $wpdb->prefix . $this->_appearance_table_name;

		if ( ! $this->_appearance_table_exists() ) {
			if ( ! empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
				$charset_collate .= " COLLATE $wpdb->collate";
		
			$query = "
				CREATE TABLE {$app_table} (
					appearance_object_id bigint(20) unsigned NOT NULL auto_increment,
					appearance_date datetime NOT NULL default '0000-00-00 00:00:00',
					PRIMARY KEY  (appearance_object_id)
				) {$charset_collate};
			";

			$wpdb->query($query);

			return $this->_appearance_table_exists();
		} else {
			return true;
		}
	}

	public function event_init()
	{
		load_plugin_textdomain('appearance-date');
	}

	public function filter_posts_fields($f = '')
	{
		$f .= ", appear_date.appearance_date ";
		return $f;
	}

	/**
	 * Extend the join clause to include the appearance date where appropos.
	 *
	 * @param string $j The join clause.
	 * @return string The join clause, filtered.
	 */
	public function filter_posts_join($j = '')
	{
		global $wpdb;
		$app_table = $wpdb->prefix . $this->_appearance_table_name;

		$j .= " LEFT JOIN {$app_table} AS appear_date ON {$wpdb->posts}.ID = appear_date.appearance_object_id ";
		return $j;
	}

	/**
	 * Filter posts results.  Meant mainly to counteract hard-coded unset of future posts, when applicable.
	 *
	 * @param array $posts The posts returned from the query.
	 * @return array The posts, filtered.
	 */
	public function filter_posts_results($posts = array())
	{
		// WP as of 2.9.2 at least unsets the results if singular and user not logged in.
		if ( 
			! is_user_logged_in() &&
			! empty( $posts ) &&
			'future' == get_post_status($posts[0]) &&
			! empty( $posts[0]->appearance_date ) &&
			( $posts[0]->appearance_date < date('Y-m-d H:i:s') )

		) {
			array_unshift($this->_posts_save, $posts);
		}
		return $posts;
	}

	/**
	 * Extend the where clause where appropos.
	 *
	 * @param string $w The Where clause.
	 * @return string The Where clause, filtered.
	 */
	public function filter_posts_where($w = '')
	{
		global $wpdb;

		// handle possibly early-appearing future posts
		$w = str_replace(
			"{$wpdb->posts}.post_status = 'publish'",

			"( {$wpdb->posts}.post_status = 'publish' OR
				(
					{$wpdb->posts}.post_status = 'future' AND 
					NOW() > appear_date.appearance_date
				)
			)",

			$w
		);

		// handle possibly late-appearing published posts
		$w .= " AND ( ( appear_date.appearance_date IS NULL ) OR ( NOW() > appear_date.appearance_date ) ) ";
		return $w;
	}

	/**
	 * Filter finalized posts results.  Meant mainly to counteract hard-coded unset of future posts, when applicable.
	 *
	 * @param array $posts The posts returned from the query and modified.
	 * @return array The posts, filtered.
	 */
	public function filter_the_posts($posts = array())
	{
		// WP as of 2.9.2 at least unsets the results if singular and user not logged in.
		if ( 
			empty( $posts ) &&
			! is_user_logged_in()
		) {
			$_posts = array_shift($this->_posts_save);
			if ( 
				is_array($_posts) &&
				isset($_posts[0]) &&
				! empty( $_posts[0]->appearance_date ) &&
				( $_posts[0]->appearance_date < date('Y-m-d H:i:s') )
			) {
				$posts = $_posts;
			}
		}
		return $posts;
	}

	/** 
	 * Get the appearance date of a given object.
	 *
	 * @param int $object_id The ID of the object for which to retreive the date.
	 * @return string The MySQL date formatted appearance date if one exists, or false if one does not.
	 */
	public function get_appearance_date($post_id = 0)
	{
		global $wpdb;
		$post_id = (int) $post_id;
		$id = '_f_ap_date_for_' . $post_id;
		$app_table = $wpdb->prefix . $this->_appearance_table_name;

		if ( ! $date = get_transient($id) ) {
			$date = $wpdb->get_var("SELECT appearance_date FROM {$app_table} WHERE appearance_object_id = {$post_id} LIMIT 1");

			if ( empty( $date ) ) {
				$date = false;
			} else {
				set_transient($id, $date);
			}
		}

		return $date;
	}

	public function maybe_install_table()
	{
		// check to make sure everything is installed for this particular site
		$installed = (bool) get_option('_filosofo_ap_date_installed');
		if ( ! $installed ) {
			$result = $this->_install_appearance_table();
			if ( $result ) {
				update_option('_filosofo_ap_date_installed', true);
			}
		}
	}
	
	/** 
	 * Set the appearance date of a given object.
	 *
	 * @param int $object_id The ID of the object for which to set the date.
	 * @param string $date The MySQL-formatted date string to set the appearance date to.
	 *  If $date is an empty string, will delete the appearance date row.
	 * @return bool Whether the saving was successful.
	 */
	public function set_appearance_date($post_id = 0, $date = '')
	{
		global $wpdb;
		$post_id = (int) $post_id;

		$id = '_f_ap_date_for_' . $post_id;

		$app_table = $wpdb->prefix . $this->_appearance_table_name;
		$date_data = array(
			'appearance_date' => $date,
		);
		$date_where = array(
			'appearance_object_id' => $post_id,
		);

		delete_transient($id);

		if ( empty( $date ) ) {
			$result = (int) $wpdb->query("DELETE FROM {$app_table} WHERE appearance_object_id = {$post_id}");
		} else {
			$result = (int) $wpdb->update(
				$app_table, 
				$date_data,
				$date_where
			);

			if ( 0 == $result ) {
				$result = (int) $wpdb->insert(
					$app_table, 
					array_merge(
						$date_data,
						$date_where
					)
				);
			}
		}
		return ( 0 < $result );
	}

}

class Filosofo_Appearance_Date_Admin {
	
	public function event_admin_init()
	{
		$this->query->maybe_install_table();
	}

	public function event_wp_insert_post($post_id = 0, $post_obj = null)
	{
		$post_id = (int) $post_id;
		if ( 
			! empty( $post_id ) &&
			isset( $_POST['saving-ap-date-settings'] ) 
		) {
			$use_it = (bool) $_POST['use-ap-date'];
			
			if ( $use_it ) {
				$Y = zeroise((int) $_POST['ap-aa'], 4);
				$m = zeroise((int) $_POST['ap-mm'], 2);
				$d = zeroise((int) $_POST['ap-jj'], 2);

				$H = zeroise((int) $_POST['ap-hh'], 2);
				$i = zeroise((int) $_POST['ap-mn'], 2);
				$s = '00';

				$date = "{$Y}-{$m}-{$d} {$H}:{$i}:{$s}";
			} else {
				$date = false;	
			}

			$this->query->set_appearance_date($post_id, $date);
		}
	}

	public function print_appearance_date_chooser()
	{
		global $post;

		if ( ! current_user_can('publish_posts') || ! isset( $post->ID ) ) {
			return false;
		}

		$post_id = (int) $post->ID;

		$current_ap_date = $this->query->get_appearance_date($post_id);

		if ( empty( $current_ap_date ) ) {
			$use_ap_date = false;
			$current_ap_date = get_post_time('Y-m-d H:i:s', false, $post_id);
		} else {
			$use_ap_date = true;
		}

		?>
		<div id="ap-date-wrap">
			<label for="use-ap-date">
				<?php _e('Show starting on appearance date?', 'appearance-date'); ?>
				<input type="hidden" name="saving-ap-date-settings" value="1" />
				<input type="checkbox" <?php
					if ( true === $use_ap_date ) {
						echo ' checked="checked"';
					}
				?> value="1" id="use-ap-date" name="use-ap-date" />
			</label>

			<div id="ap-date-selects">
				<p><strong><?php _e('Appearance date:', 'appearance-date'); ?></strong></p>
				<?php
					$this->_print_time_selects($current_ap_date);
				?>
			</div>

			<script type="text/javascript">
			// <![CDATA[
			(function() {
				var useCheckbox = document.getElementById('use-ap-date'),
				dateSelects = document.getElementById('ap-date-selects');

				if ( dateSelects && useCheckbox ) {
					if ( ! useCheckbox.checked ) 
						dateSelects.style.display = 'none';

					useCheckbox.onclick = function() {
						dateSelects.style.display = this.checked ? 'block' : 'none';
					}
				}
			})();
			// ]]>
			</script>
		</div>
		<?php
	}

	public function print_style()
	{
		?>
		<style type="text/css">
			#ap-date-wrap {
				padding:.5em;
			}
		</style>
		<?php
	}
	
	private function _print_time_selects( $post_date = null ) {
		global $wp_locale;

		$time_adj = current_time('timestamp');

		$jj = empty( $post_date ) ? gmdate( 'd', $time_adj ) : mysql2date( 'd', $post_date, false );
		$mm = empty( $post_date ) ? gmdate( 'm', $time_adj ) : mysql2date( 'm', $post_date, false );
		$aa = empty( $post_date ) ? gmdate( 'Y', $time_adj ) : mysql2date( 'Y', $post_date, false );
		$hh = empty( $post_date ) ? gmdate( 'H', $time_adj ) : mysql2date( 'H', $post_date, false );
		$mn = empty( $post_date ) ? gmdate( 'i', $time_adj ) : mysql2date( 'i', $post_date, false );
		$ss = empty( $post_date ) ? gmdate( 's', $time_adj ) : mysql2date( 's', $post_date, false );

		$month = "<select id=\"ap-mm\" name=\"ap-mm\">\n";
		for ( $i = 1; $i < 13; $i = $i +1 ) {
			$month .= "\t\t\t" . '<option value="' . zeroise($i, 2) . '"';
			if ( $i == $mm )
				$month .= ' selected="selected"';
			$month .= '>' . $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) . "</option>\n";
		}
		$month .= '</select>';

		$day = '<input type="text" id="ap-jj" name="ap-jj" value="' . $jj . '" size="2" maxlength="2" autocomplete="off" />';
		$year = '<input type="text" id="ap-aa" name="ap-aa" value="' . $aa . '" size="4" maxlength="4" autocomplete="off" />';
		$hour = '<input type="text" id="ap-hh" name="ap-hh" value="' . $hh . '" size="2" maxlength="2" autocomplete="off" />';
		$minute = '<input type="text" id="ap-mn" name="ap-mn" value="' . $mn . '" size="2" maxlength="2" autocomplete="off" />';

		echo '<div class="timestamp-wrap">';
		/* translators: 1: month input, 2: day input, 3: year input, 4: hour input, 5: minute input */
		printf(__('%1$s%2$s, %3$s @ %4$s : %5$s', 'appearance-date'), $month, $day, $year, $hour, $minute);

		echo '</div>';
	}
}

function init_filosofo_appearance_date()
{
	new Filosofo_Appearance_Date_Factory; 
}

function uninstall_filosofo_appearance_date()
{
	global $wpdb;
	$app_table = $wpdb->prefix . 'object_appearance_dates';
	$wpdb->query(sprintf('DROP TABLE %s', $app_table));
	delete_option('_filosofo_ap_date_installed');
}

add_action('plugins_loaded', 'init_filosofo_appearance_date');
register_uninstall_hook(__FILE__, 'uninstall_filosofo_appearance_date');
