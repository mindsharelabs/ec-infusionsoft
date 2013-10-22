<?php

/*
Plugin Name: EC Infusionsoft
Plugin URI: http://evolutionarycollective.com/
Description: Automates ECC replay and post notifications through Infusionsoft's PHP SDK
Version: 1.0
Author: Mindshare Studios
Author URI: http://mindsharelabs.com/
*/

/**
 * @copyright Copyright (c) 2013. All rights reserved.
 * @author    Mindshare Studios, Inc.
 *
 * @license   Released under the GPL license http://www.opensource.org/licenses/gpl-license.php
 * @see       http://wordpress.org/extend/plugins/wp-ultimate-search/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * **********************************************************************
 *
 */

class Infusionsoft_API {
	
	private $app;
	
	public function __construct() {
		add_action('wp_insert_post', array($this, 'schedule_replay_notification'));				// when a post is added, schedule the replay notification
		add_action('wp_trash_post', array($this, 'remove_replay_notification'));				// when a post is trashed, remove the replay notification
		add_action('send_post_event', array($this, 'send_new_post_announcement'));
		//add_action('wp_enqueue_scripts', array($this, 'send_new_post_announcement'));
	}
	
	private function connect() {
		require("isdk.php");  
		$this->app = new iSDK;

		if($this->app->cfgCon("fu119", "0f1308d6a1fe9b06c4c1311ad5f96ef9")){
			return true;
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 * Get contacts
	 *
	 *
	 * Queries infusionsoft for an array of contact IDs with a given tag ID
	 *
	 * @param tag
	 *
	 * @return array
	 */
	
	private function get_contacts($type) {
		// max contacts in a request is 1000
		if($type == 'ecc') {
			// group ID for 24h automated replay is 123
			return $this->app->dsFind('ContactGroupAssign',1000,0,'GroupId',123,array('ContactId'));
		} else {
			// group ID for Blog to Email is 221
			return $this->app->dsFind('ContactGroupAssign',1000,0,'GroupId',221,array('ContactId'));
		}
	}
	/**
	 *
	 * Get template
	 *
	 *
	 * Queries infusionsoft for a template with the given ID and returns it as an array
	 *
	 * @return array
	 */
	
	private function get_template() {
		return $this->app->getEmailTemplate(3413);
	}
	
	
	public function schedule_replay_notification($post_id) {
		$slug = 'conversation';

	    $_POST += array("{$slug}_edit_nonce" => '');
	    if ( $slug != $_POST['post_type'] ) {
			if ( wp_is_post_revision( $post_id ) == false ) {
	        	if($_POST['post_type'] == "post" && $_POST['post_status'] == "publish" ) {
					$post = get_post($post_id);
					if ($post->post_date != $post->post_modified) return; // quit if post has been published already
					$this->send_blog_post_notification($post_id);
					return;
				}
			}
	    }
	    if ( !current_user_can( 'edit_post', $post_id ) ) {
	        return;
	    }

		if(get_field('air_date', $post_id)) {
			// set the hour to 19:00GMT (12pm MST) by default
			$air_date = DateTime::createFromFormat('YmdHi', (get_field('air_date', $post_id) . "1900"));
			// schedule email notification for 12 hours after the show has aired
			wp_schedule_single_event($air_date->format('U')+43200, 'send_post_event', array($post_id));
		}
	}
	
	public function remove_replay_notification($post_id) {
		if(get_field('air_date', $post_id)) {
			$air_date = DateTime::createFromFormat('YmdHi', (get_field('air_date', $post_id) . "1900"));
			wp_unschedule_event($air_date->format('U')+43200, 'send_post_event', array($post_id));
		}
	}
	
	public function send_blog_post_notification($post_id) {
		$permalink = get_permalink($post_id);
		$posttitle = get_the_title($post_id);
		global $post;
		$content = get_post($post_id)->post_content;
		$excerpt = wp_trim_words( $content, $num_words = 55, $more = null );
		$postdate = get_post($post_id)->post_date;
		
		$this->connect();
		$contacts = $this->get_contacts('blogtoemail');
		
		$template = $this->app->getEmailTemplate(3717);

		$permalink .= "?utm_source=blogtoemail&utm_medium=email&utm_campaign=blogtoemail" . date("mdy");

		$template["htmlBody"] = str_replace("<atrackfix", "<a", $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_titlelink_", $permalink, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_titletext_", $posttitle, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_postdate_", $postdate, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_excerpt_", $excerpt, $template["htmlBody"]);

		// Contact ID for bryce@mindsharestudios.com is 10035
		// $clist[] = 10035;

		foreach ($contacts as $contact) {
			$clist[] = $contact['ContactId'];
		}

		$posttitle = str_replace('&#038;', 'and', $posttitle);

		if($this->app->sendEmail($clist,"info@evolutionarycollective.com","~Contact.Email~", "","","HTML","Evolutionary Collective - New Post: " . mb_encode_mimeheader($posttitle, "UTF-8", "B"),$template["htmlBody"],$template["textBody"]) == 1) {
			return true;
		} else {
			return false;
		}
		
	}

	public function send_campaign($contacts, $permalink, $posttitle, $postdate, $shortdate, $excerpt) {
		
		$template = $this->app->getEmailTemplate(3413);

		$permalink .= "?utm_source=ecc_replay&utm_medium=email&utm_campaign=ecc_replay_" . $shortdate;

		$template["htmlBody"] = str_replace("<atrackfix", "<a", $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_titlelink_", $permalink, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_titletext_", $posttitle, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_postdate_", $postdate, $template["htmlBody"]);
		$template["htmlBody"] = str_replace("_excerpt_", $excerpt, $template["htmlBody"]);

		// Contact ID for bryce@mindsharestudios.com is 10035
		//$clist[] = 10035;

		foreach ($contacts as $contact) {
			$clist[] = $contact['ContactId'];
		}

		if($this->app->sendEmail($clist,"info@evolutionarycollective.com","~Contact.Email~", "","","HTML","Evolutionary Collective Conversations: " . $posttitle,$template["htmlBody"],$template["textBody"]) == 1) {
			return true;
		} else {
			return false;
		}
		
	}
	
	public function send_new_post_announcement($post_id) {

		// first, get the audio recording of the webinar
		if(get_field('air_date', $post_id)) {
			$air_date = DateTime::createFromFormat('YmdHi', (get_field('air_date', $post_id) . "1900"));
			$datestring = $air_date->format('mdy');
			
			$result = wp_remote_get( 'http://contacttalkradio.net/CTR/patriciaalbere' . $datestring .'.mp3', array('timeout' => 90) );
			
			if( is_wp_error( $result ) ) {
			   echo 'Something went wrong!';
			} else {
			   	$recording = wp_upload_bits( 'patriciaalbere' . $datestring .'.mp3', null, $result['body']);
				update_field('field_50a7b5b30dcf8', $recording['url'], $post_id);
			}
		}
		
		$permalink = get_permalink($post_id);
		$posttitle = get_the_title($post_id);
		$postdate = $air_date->format('l, F j');
		$shortdate = $air_date->format('m-d-y');
		global $post;
		$content = get_post($post_id)->post_content;
		$excerpt = wp_trim_words( $content, $num_words = 55, $more = null );
		
		$this->connect();
		$contacts = $this->get_contacts('ecc');
		$this->send_campaign($contacts, $permalink, $posttitle, $postdate, $shortdate, $excerpt);
	}
}

if(class_exists("Infusionsoft_API")) {
	$isdk = new Infusionsoft_API;
}

?>