<?php
/*
Plugin Name: Greenhouse Jobboard Listings Plugin
Description: Greenhouse.io is an online software that helps companies post jobs online, manage applicants and hire great employees.
Plugin URI: http://www.niklasdahlqvist.com
Author: Niklas Dahlqvist
Author URI: http://www.niklasdahlqvist.com
Version: 1.0.0
Requires at least: 4.9.5
License: GPL
*/

/*
   Copyright 2018  Niklas Dahlqvist  (email : dalkmania@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
* Ensure class doesn't already exist
*/
if(! class_exists ("Greenhouse_Jobboards_Plugin") ) {

  class Greenhouse_Jobboards_Plugin {
    private $options;
    private $apiBaseUrl;

    /**
     * Start up
     */
    public function __construct() {
      $this->options = get_option( 'greenhouse_jobboards_settings' );
      $this->board_token = $this->options['board_token'];
      $this->apiBaseUrl = 'https://api.greenhouse.io/v1/boards/';

      add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
      add_action( 'admin_init', array( $this, 'page_init' ) );
      add_action('wp_enqueue_scripts', array($this,'plugin_admin_styles'));
      add_shortcode('greenhouse_job_listings', array( $this,'JobsShortCode') );
    }

    public function plugin_admin_styles() {
      wp_enqueue_style('greenhouse_jobboards-admin-styles', $this->getBaseUrl() . '/assets/css/plugin-admin-styles.css');
    }

    /**
     * Add options page
     */
    public function add_plugin_page() {
        // This page will be under "Settings"
        add_management_page(
            'Greenhouse.io Settings Admin',
            'Greenhouse.io Settings',
            'manage_options',
            'greenhouse_jobboards-settings-admin',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page() {
        // Set class property
        $this->options = get_option( 'greenhouse_jobboards_settings' );
        ?>
        <div class="wrap greenhouse_jobboards-settings">
          <h2>Greenhouse.io Settings</h2>
          <form method="post" action="options.php">
          <?php
              // This prints out all hidden setting fields
              settings_fields( 'greenhouse_jobboards_settings_group' );
              do_settings_sections( 'greenhouse_jobboards-settings-admin' );
              submit_button();
          ?>
          </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init() {

      register_setting(
          'greenhouse_jobboards_settings_group', // Option group
          'greenhouse_jobboards_settings', // Option name
          array( $this, 'sanitize' ) // Sanitize
      );

      add_settings_section(
          'greenhouse_jobboards_section', // ID
          '', // Title
          array( $this, 'print_section_info' ), // Callback
          'greenhouse_jobboards-settings-admin' // Page
      );

      add_settings_field(
          'board_token', // ID
          'Greenhouse Board Token', // Title
          array( $this, 'greenhouse_jobboards_board_token_callback' ), // Callback
          'greenhouse_jobboards-settings-admin', // Page
          'greenhouse_jobboards_section' // Section
      );
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input ) {
      $new_input = array();
      if( isset( $input['board_token'] ) )
          $new_input['board_token'] = sanitize_text_field( $input['board_token'] );

      return $new_input;
    }

    /**
     * Print the Section text
     */
    public function print_section_info() {
      echo '<p>Enter your settings below:';
      echo '<br />and then use the <strong>[greenhouse_job_listings]</strong> shortcode to display the content.</p>';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function greenhouse_jobboards_board_token_callback() {
      printf(
          '<input type="text" id="board_token" class="narrow-fat" name="greenhouse_jobboards_settings[board_token]" value="%s" />',
          isset( $this->options['board_token'] ) ? esc_attr( $this->options['board_token']) : ''
      );
    }

    public function JobsShortCode($atts, $content = null) {
      $output = '';
      if(isset($this->board_token) && $this->board_token != '') {
        $positions = $this->get_greenhouse_positions();
        $locations = $this->get_greenhouse_locations($positions);
        $departments = $this->get_greenhouse_departments($positions);

        foreach ($departments as $department) {
          $output .= '<div class="job-section">';
          $output .= '<h3 class="title">'. ucwords($department) .'</h3>';
          $output .= '<ul class="job-listings">';

          foreach ($positions as $position) {
            if($position['department'] == $department) {
              $output .= '<li class="job-listing">';
              $output .= '<a class="posting-title" href="' . $position['hostedUrl'] . '">';
              $output .= '<h4>' . $position['title'] . '</h4>';
              $output .= '<div class="posting-categories">';
              $output .= '<span href="#" class="sort-by-location posting-category">' . $position['location'] . '</span>';
              $output .= '</div>';
              $output .= '</a>';
              $output .= '</li>';
            }
          }
          $output .= '</ul>';
        $output .= '</div>';
        }

        return $output;
      }
    }

    // Send Curl Request to Lever Endpoint and return the response
    public function sendRequest($endpoint) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl.$this->board_token .'/' .$endpoint. '/?content=true');
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      $response = json_decode(curl_exec($ch),true);
      return $response;
    }

    public function get_greenhouse_positions() {
      // Get any existing copy of our transient data
      if ( false === ( $greenhouse_data = get_transient( 'greenhouse_positions' ) ) ) {
        // It wasn't there, so make a new API Request and regenerate the data
        $positions = $this->sendRequest('jobs');
        if( $positions != '' ) {
          $greenhouse_data = array();

          foreach($positions['jobs'] as $item) {
            $greenhouse_position = array(
              'id' => $item['internal_job_id'],
              'title' => $item['title'],
              'location' => $item['location']['name'],
              'department' => $item['departments']['0']['name'],
              'hostedUrl' => $item['absolute_url'],
              'createdAt' => $item['updated_at']
            );

            array_push($greenhouse_data, $greenhouse_position);
          }
        }
        // Cache the Response
        $this->storeGreenhousePostions($greenhouse_data);
      } else {
        // Get any existing copy of our transient data
        $greenhouse_data = unserialize(get_transient( 'greenhouse_positions' ));
      }
      // Finally return the data
      return $greenhouse_data;
    }

    public function get_greenhouse_locations($positions) {
      $locations = array();
      foreach ($positions as $position) {
        $locations[]  = $position['location'];
      }

      $locations = array_unique($locations);
      sort($locations);

      return $locations;
    }



    public function get_greenhouse_departments($positions) {
      $departments = array();

      foreach ($positions as $position) {
        $departments[]  = $position['department'];
      }

      $departments = array_unique($departments);
      sort($departments);

      return $departments;
    }

    public function storeGreenhousePostions( $positions ) {
      // Get any existing copy of our transient data
      if ( false === ( $greenhouse_data = get_transient( 'greenhouse_positions' ) ) ) {
        // It wasn't there, so regenerate the data and save the transient for 12 hours
        $greenhouse_data = serialize($positions);
        set_transient( 'greenhouse_positions', $greenhouse_data, 24 * HOUR_IN_SECONDS );
      }
    }

    public function flushStoredInformation() {
      //Delete transient to force a new pull from the API
      delete_transient( 'greenhouse_positions' );
    }

    //Returns the url of the plugin's root folder
    protected function getBaseUrl() {
      return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function getBasePath() {
      $folder = basename(dirname(__FILE__));
      return WP_PLUGIN_DIR . "/" . $folder;
    }

  } //End Class

  /**
   * Instantiate this class to ensure the action and shortcode hooks are hooked.
   * This instantiation can only be done once (see it's __construct() to understand why.)
   */
  new Greenhouse_Jobboards_Plugin();

} // End if class exists statement
