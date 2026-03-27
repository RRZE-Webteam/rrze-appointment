<?php

namespace RRZE\ShortURL;

use \RRZE\AccessControl\Permissions;
use RRZE\ShortURL\CustomException;

class Rights
{
    private $idm = null;

    public function __construct()
    {
        $this->idm = 'system';
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $this->idm = $current_user->user_nicename;
        } elseif (class_exists('\RRZE\AccessControl\Permissions')) {
            $permissionsInstance = new Permissions();
            $checkSSOLoggedIn = $permissionsInstance->checkSSOLoggedIn();
            $personAttributes = $permissionsInstance->personAttributes;
            $this->idm = (!empty($personAttributes['uid'][0]) ? $personAttributes['uid'][0] : null);
        } else {
            error_log('\RRZE\AccessControl\Permissions is not available');
        }

        add_action('init', [$this, 'getRights']);
    }

    public function getRights(): array
    {
        $this->idm = sanitize_text_field($this->idm);


        if (is_user_logged_in()) {
            $aRet = [
                'idm' => $this->idm,
                'allow_uri' => true,
                'allow_get' => true,
                'allow_utm' => true
            ];
            return $aRet;
        }else{
            // Default return array with no rights
            $aRet = [
                'idm' => $this->idm,
                'allow_uri' => false,
                'allow_get' => false,
                'allow_utm' => false
            ];    
        }

        try {
            $args = [
                'post_type' => 'shorturl_idm',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'name' => $this->idm
            ];
            
            $query = new \WP_Query($args);

            // Check if a matching IDM post was found
            if (!empty($query->posts)) {
                $post_id = $query->posts[0];
                $post_meta = get_post_meta($post_id);

                // Fetch the rights from the post meta
                $aRet['allow_uri'] = isset($post_meta['allow_uri'][0]) ? (bool) $post_meta['allow_uri'][0] : false;
                $aRet['allow_get'] = isset($post_meta['allow_get'][0]) ? (bool) $post_meta['allow_get'][0] : false;
                $aRet['allow_utm'] = isset($post_meta['allow_utm'][0]) ? (bool) $post_meta['allow_utm'][0] : false;

                // Restore original Post Data
                wp_reset_postdata();
            } else {
                if (!empty($this->idm)) {
                    $post_data = [
                        'post_title' => $this->idm,
                        'post_type' => 'shorturl_idm',
                        'post_status' => 'publish'
                    ];

                    $inserted_post_id = wp_insert_post($post_data);

                    if ($inserted_post_id) {
                        update_post_meta($inserted_post_id, 'allow_uri', 0);
                        update_post_meta($inserted_post_id, 'allow_get', 0);
                        update_post_meta($inserted_post_id, 'allow_utm', 0);
                    }
                }
            }

            return $aRet;
        } catch (CustomException $e) {
            // Log the error and return the default rights array
            error_log('Error fetching rights: ' . $e->getMessage());
            return $aRet;
        }
    }

}
