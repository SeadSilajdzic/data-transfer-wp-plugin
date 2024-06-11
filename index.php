<?php
/**
 * Plugin Name: Data transfer plugin
 * Description: Automatization helper laravel developed centralized system and wp websites. Note: Site has to have specific theme developed.
 * Version: 3.0
 * Author: Sead Silajdzic
 */
class DataTransferPlugin {
    public $templates = ['allowType1', 'allowType2', 'allowType3', 'allowType4'];
    public $sites = ['https://site1.com', 'https://site2.com'];

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        register_rest_route('data_transfer/v2', '/create-post', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
        ));
    }

    public function create_post($request) {
        $site = $request->get_header('app_request_from');

        if(in_array($site, $this->sites)) {
            // Tokens
            $provided_token = $request->get_header('Authorization');
            $expected_token = //bearer token;

            // Uuid
            $uuid = $request->get_header('app_request_authentication_uuid');

            //$uuid .= 'a'; // If you want to test security layer, uncomment line but don't forget to comment it back!
            
            $endpoint = $site . '/api/check-if-uuid-exist/' . $uuid;

            // Make the API request
            $response  = wp_remote_get($endpoint);
            $body = wp_remote_retrieve_body($response);

            if($body == true) {
                // If tokens does not match, throw error
                if ($provided_token !== $expected_token) {
                    return new WP_Error('unauthorized', 'Unauthorized', array('status' => 401));
                }


                global $wpdb;

                // Check if this is old theme
                $isOldCrTheme = $request->get_header('app_request_old_cr_support');

                // Get template and check if it is valid one
                $template = $request->get_param('post_type');

                if(in_array($template, $this->templates)) {
                    // Meta is for acf fields
                    $meta = $request->get_param('meta');

                    if($template == 'allowType1' || $template == 'allowType2' || $template == 'allowType3') {
                        $this->articlesWorkflow($wpdb, $request, $template, $meta, $isOldCrTheme);
                    } elseif($template == 'allowType4') {
                        if($request->get_param('allowType4s')) {
                            $this->allowType4Workflow($wpdb, $request, $template, $meta, $isOldCrTheme);
                        }
                    } else {
                        return new WP_Error('unsupported_template', 'We don\'t support this template yet!', array('status' => 404));
                    }
                } else {
                    // If invalid template is provided, throw error
                    return new WP_Error('template_not_found', 'Template you provided does not exist or is not supported!.', array('status' => 404));
                }
            } else {
                return new WP_Error('unauthorized-access', 'You are not authorized to do this action!', array('status' => 401));
            }
        } else {
            return new WP_Error('unauthorized-access', 'You are not authorized to do this action!', array('status' => 401));
        }
    }

    private function articlesWorkflow($wpdb, $request, $template, $meta, $isOldCrTheme) {
        // Get general fields
        $post_type = $request->get_param('post_type');
        $post_title = $request->get_param('post_title');
        $post_author = get_user_by('email', $request->get_param('post_author'))->data->ID;
        $post_status = $request->get_param('post_status');
        $excerpt = $request->get_param('excerpt');

        // Set general fields
        $post_data = array(
            'post_type'     => $post_type,
            'post_title'    => $post_title,
            'post_author'   => $post_author,
            'post_status'   => $post_status,
            'post_excerpt'  => $excerpt,
        );
    
        // Create post
        $new_post_id = wp_insert_post($post_data);
        // If there are any issues throw error
        if (is_wp_error($new_post_id)) {
            return new WP_Error('create_post_error', 'Could not create post.', array('status' => 500));
        }

        // Get seo fields and update values in article
        $this->setupYoastSeoFields($request, $new_post_id);

        // Set name of the user who imported allowType4 in last modified by
        $this->modifyDate($request, $new_post_id);

        $createdallowType4sIds = [];
        // After post is created, create allowType4s if there are any and make links to the article
        if($request->get_param('allowType4s')) {
            // Get allowType4s
            $allowType4s = $request->get_param('allowType4s');
            $returnArrayFromallowType4s = $this->setupallowType4s($wpdb, $request, $allowType4s, $new_post_id, $isOldCrTheme);
        }

        $this->proceedWithArraySetupAndDataImport($request, $template, $meta, $new_post_id);

        // Select users ids and attach them to the post
        $this->setupUsers($meta, $new_post_id);

        // Format response so we can fetch it in the database
        $responseArray = $this->formatHTTPResponse($new_post_id, $template, $returnArrayFromallowType4s);
    
        // If everything is fine, throw success message
        return new WP_REST_Response($responseArray, 200);
    }

    private function allowType4Workflow($wpdb, $request, $template, $meta, $isOldCrTheme) {
        $url = $request->get_param('url');
        $slug = explode('/', $url)[3];
        
        $query = $wpdb->prepare(
            "SELECT ID 
            FROM {$wpdb->posts} 
            WHERE post_name = %s",
            $slug
        );

        $post_id = $wpdb->get_var($query);

        if($post_id) {
            // Get allowType4s
            $allowType4s = $request->get_param('allowType4s');
            $returnArrayFromallowType4s = $this->setupallowType4s($wpdb, $request, $allowType4s, $post_id, $isOldCrTheme);

            $this->proceedWithArraySetupAndDataImport($request, $template, $meta, $post_id);

            // Format response so we can fetch it in the database
            $responseArray = $this->formatHTTPResponse($post_id, $template, $returnArrayFromallowType4s);

            // If everything is fine, throw success message
            return new WP_REST_Response($responseArray, 200);
        } else {
            return WP_Error('wp_post_not_found', 'We could not find post with' . $slug . ' slug!', ['status' => 404]);
        }
    }

    private function proceedWithArraySetupAndDataImport($request, $template, $meta, $post_id) {
        // Show last update
        if (isset($meta['logs'])) {
            update_field('last_update', 'yes', $post_id);
        }

        // Setup fields in request so we can import data
        $providedRequestMeta = $request->get_param('meta');
        $templateFields = $this->getFieldsSet($template);

        $contentFields = array_keys($providedRequestMeta);
        foreach($templateFields as $key => $field) {
            if(is_array($field)) {
                foreach($field as $fieldArrayKey => $fieldArrayValue) {
                    if(!in_array($fieldArrayKey, $contentFields)) {
                        unset($templateFields[$key]);
                    }
                }
            } else {
                if(!in_array($field, $contentFields)) {
                    unset($templateFields[$key]);
                }
            }
        }

        // Loop which will automatically load fields for the provided template and insert values
        if (is_array($meta) && !empty($meta)) {
            foreach ($templateFields as $field) {
                if (is_array($field)) {
                    $key = array_keys($field)[0];
                    $field = array_values($meta[array_keys($field)[0]]);
                    update_field($key, $field, $post_id);
                } else {
                    update_field($field, $meta[$field], $post_id);
                }
            }
        }
    }

    // ====> In next big release make this function responsible for all kinds of feedbacks instead of WP_ERROR
    private function formatHTTPResponse($new_post_id, $template, $returnArrayFromallowType4s) {
        $responseArray = [
            'message' => 'Post created successfully.', 
            'article_id' => $new_post_id,
            'article_template' => $template,
        ];

        
        if(!empty($returnArrayFromallowType4s)) {
            $responseArray['allowType4_ids'] = $returnArrayFromallowType4s[0];
            $responseArray['afp_data'] = $returnArrayFromallowType4s[1];
        }

        return $responseArray;
    }

    private function setupallowType4s($wpdb, $request, $allowType4s, $new_post_id, $isOldCrTheme) {
        $createdallowType4sIds = [];

        foreach ($allowType4s as $allowType4_data) {
            $allowType4_meta = $allowType4_data['meta'];

            $allowType4_args = array(
                'post_type'   => $allowType4_data['post_type'],
                'post_title'  => $allowType4_meta['allowType4_name'],
                'post_author' => $allowType4_meta['post_author'],
                'post_status' => $allowType4_data['post_status'],
            );
        
            // Create compa-allowType4
            $allowType4_id = wp_insert_post($allowType4_args);
            $createdallowType4sIds[]['select_allowType4'][] = $allowType4_id;

            // Show bottom line
            if (isset($allowType4_meta['bottom_line'])) {
                update_field('show_bottom_line', 'yes', $allowType4_id);
            }

            // Show kdmf section
            if (isset($allowType4_meta['headline']) || isset($allowType4_meta['kdmf_text'])) {
                update_field('show_kdmf', 'yes', $allowType4_id);
            }

            // Show bottom line
            if (isset($allowType4_meta['comments_from_users'])) {
                update_field('add_comments', 'yes', $allowType4_id);
            }

            if(!$isOldCrTheme) {
                $specification_key = 'specification';
            } else {
                $specification_key = 'specification_new';
            }

            // Prepare specifications for import into acf fields
            if($allowType4_meta[$specification_key]) {
                foreach($allowType4_meta[$specification_key] as $key => $specification_fields) {
                    foreach($specification_fields as $specification_field => $specification_value) {
                        if($specification_field == 'specif') {
                            $specification_value = trim($specification_value);
        
                            // Check if the specification already exists in the 'specifications' taxonomy
                            $existing_term = get_term_by('name', $specification_value, 'specifications');
                            
                            // Specif is key where we get specification name
                            if ($existing_term && !is_wp_error($existing_term)) {
                                // Use existing term
                                $term_id = $existing_term->term_id;
                            } else {
                                // Create a new term
                                $term = wp_insert_term(
                                    $specification_value,
                                    'specifications'
                                );
                    
                                if (is_array($term) && isset($term['term_id'])) {
                                    $term_id = $term['term_id'];
                                } else {
                                    // Handle term creation error
                                    continue;
                                }
                            }
        
                            $allowType4_meta[$specification_key][$key][$specification_field] = $term_id;
                        }
                    }
                }
            }

            // Check if we have price urls
            if(isset($allowType4_meta['price_urls'])) {
                // Store urls into variable and get each url once
                $prices = array_unique($allowType4_meta['price_urls']);

                $filteredLinks = [];
                // Don't check for pricerunner or prisjakt in resellers table
                foreach ($prices as $link) {
                    if (strpos($link, 'pricerunner') === false && strpos($link, 'prisjakt') === false) {
                        $filteredLinks[] = $link;
                    }
                }

                if(!empty($filteredLinks)) {
                    // Loop prices
                    foreach($filteredLinks as $price) {
                        // Format proper price root url to get reseller uri (root)
                        $rootPriceUrl = str_replace('www.', '', parse_url($price, PHP_URL_HOST));

                        // Check if reseller is in db
                        $query = $wpdb->prepare(
                            "SELECT ID 
                            FROM {$wpdb->prefix}afp_resellers 
                            WHERE uri LIKE %s",
                            '%' . $rootPriceUrl . '%'
                        );

                        // Check if we have any matches
                        if(!empty($wpdb->get_results($query)[0])) {
                            // Store reseller id into variable
                            $resellerID = $wpdb->get_results($query)[0]->ID;

                            // Make slug out of allowType4 name
                            $allowType4NameToSlug = sanitize_title($allowType4_meta['allowType4_name']);

                            // Check and get/create term
                            $term = get_term_by('slug', $allowType4NameToSlug, 'pricetable');
                    
                            if($term) {
                                $termID = $term->term_id;
                            } else {
                                $term = wp_insert_term($allowType4_meta['allowType4_name'], 'pricetable');
                                $termID = $term['term_id'];
                            }

                            // Using AFP get pricetable and populate field
                            $table = AFPFactory::getPriceTable($termID);
                            if($allowType4_meta['force_pricerunner_pricetables'] === false) {
                                $table->update_meta('force_pricerunner','false');
                            }

                            update_field('allowType4_afp_id', $table->id, $allowType4_id);

                            $pricesTableName = $wpdb->prefix . 'afp_prices';

                            $dataToInsert = [
                                'tableid' => $termID,
                                'resellerid' => $resellerID,
                                'uri' => $price
                            ];

                            $dataFormat = [
                                '%d',
                                '%d',
                                '%s'
                            ];

                            $resultInsert = $wpdb->insert($pricesTableName, $dataToInsert, $dataFormat);
                        } else { // If we dont have matches, provide response message
                            $prepareAFPData['resellers'][] = 'Reseller not found: '. $rootPriceUrl;
                        }
                    }
                } else {
                    $prepareAFPData['prices'][] = 'allowType4 with ID ' . $allowType4_id . ' (in WordPress) has no other prices than from pricerunner or prisjakt in allowType1.io.';
                }
            } else {
                $prepareAFPData['prices'][] = 'allowType4 with ID ' . $allowType4_id . ' (in WordPress) has one or more prices from unknown reseller.';
            }

            if ($allowType4_meta['pricerunner_id'] && $allowType4_meta['force_pricerunner_pricetables'] === true) {
                $this->generateAfpPricetableFromPrId($allowType4_id, $allowType4_meta);
            }
            
            // Update ACF fields
            foreach ($allowType4_meta as $field_name => $field_value) {
                update_field($field_name, $field_value, $allowType4_id);
            }
            
            // If we don't have provided data for one of the repeaters, remove default fields
            if(!isset($allowType4_meta['info_list'])) {
                update_field('info_list', null, $allowType4_id);
            }

            if(!isset($allowType4_meta['fordele'])) {
                update_field('fordele', null, $allowType4_id);
            }

            if(!isset($allowType4_meta['ulemper'])) {
                update_field('ulemper', null, $allowType4_id);
            }

            if(!isset($allowType4_meta['specification'])) {
                update_field('specification', null, $allowType4_id);
            }

            update_field('article', $new_post_id, $allowType4_id);

            // We will modify date so we have the user who exported/imported allowType4
            $this->modifyDate($request, $allowType4_id);
        
            // If there are any issues, throw an error
            if (is_wp_error($allowType4_id)) {
                return new WP_Error('create_post_error', 'Could not create post with post type of compa-allowType4.', array('status' => 500));
            }
        }

        foreach ($createdallowType4sIds as $allowType4_data) {
            $allowType4_id = $allowType4_data['select_allowType4'][0]; // Extract the allowType4 ID
        
            // Get the current allowType4s repeater data for the post
            $repeater_field = get_field('allowType4s', $new_post_id);
        
            // Add new row to the allowType4s repeater field
            $new_row = array(
                'select_allowType4' => $allowType4_id
            );
        
            $repeater_field[] = $new_row;
        
            // Update the allowType4s repeater field with the modified repeater data
            update_field('allowType4s', $repeater_field, $new_post_id);
        }

        $returnArray = [$createdallowType4sIds, $prepareAFPData];
        return $returnArray;
    }

    private function modifyDate($request, $id) {
        $exported_by = get_user_by('login', $request->get_param('exported_by'));

        if(!$exported_by) {
            $exported_by = get_user_by('login', 'developer');
        }

        update_post_meta($id, '_edit_last', $exported_by->ID);
    }

    private function setupYoastSeoFields($request, $new_post_id) {
        // Get seo fields
        $yoast_slug = $request->get_param('_yoast_wpseo_focuskw');
        $yoast_title = $request->get_param('_yoast_wpseo_title');
        $yoast_description = $request->get_param('_yoast_wpseo_metadesc');

        // Set seo fields
        update_field('_yoast_wpseo_title', $yoast_title, $new_post_id);
        update_field('_yoast_wpseo_metadesc', $yoast_description, $new_post_id);
        update_field('_yoast_wpseo_focuskw', $yoast_slug, $new_post_id);

        wp_update_post([
            'ID' => $new_post_id,
            'post_name' => $yoast_slug
        ]);
    }

    private function setupUsers($meta, $new_post_id) {
        $users = [];
        foreach($meta['users'] as $key => $user) {
            $user = get_user_by('email', $user['user']);

            if($user) {
                $users[]['user'][] = $user->ID;
            }
        }

        update_field('users', $users, $new_post_id);
    }

    private function getFieldsSet($template) {
        $generalFields = [
            'title_light',
            'keyword',
            'introduction',
            ['users' => ['user']],
            ['faq' => ['accordion_title', 'accordion_text']],
        ];

        if($template == 'allowType1') {
            $fields = [
                ['buying_text' => ['title_item', 'content_item']],
                ['additional_tips' => ['tip_title', 'content']],
                ['sources_list' => ['title', 'text']],
                ['reviews_list' => ['title_review', 'text_review']],
                ['logs' => ['date_log', 'description_log']],
                'about_allowType4_category',
                'title_bold',
            ];

        } elseif($template == 'allowType3') {
            $fields = [
                ['additional_tips' => ['tip_title', 'content']],
                ['buying_text' => ['title_item', 'content_item']],
                ['logs' => ['date_log', 'description_log']],
                'about_allowType4_category',
                'title_bold',
            ];

        } elseif($template == 'allowType2') {
            $fields = [
                ['educational_block' => ['title_edu', 'content_edu']],
            ];

        } elseif($template == 'allowType4') {
            $fields = [
                ['logs' => ['date_log', 'description_log']],
                ['sources_list' => ['title', 'text']],
            ];
        }

        if($template == 'allowType1' || $template == 'allowType2' || $template == 'allowType3') {
            return array_merge($generalFields, $fields);
        } elseif($template == 'allowType4') {
            return $fields;
        }
    }

    private function generateAfpPricetableFromPrId ($allowType4_id, $allowType4_meta) {
        $compaallowType4 = get_post($allowType4_id);

        if($compaallowType4->post_type != 'compa-allowType4') {
		    return;
        }

        $pr_id = $allowType4_meta['pricerunner_id'];
        if($pr_id){
            $prDataJson = $this->getPrJson($pr_id);
            
            if($prDataJson){
                $prData = json_decode($prDataJson);
            
                
                if(isset($prData->allowType4ListingallowType4) && $prData->allowType4ListingallowType4->name){
                    $term = get_term_by('name',$prData->allowType4ListingallowType4->name,'pricetable'); 


                    if ($term) {
                        $term_id = $term->term_id;
                    } else {
                        $term = wp_insert_term($prData->allowType4ListingallowType4->name,'pricetable');
                        $term_id = $term['term_id'];
                    }
                        
                                                                    
                    $table = AFPFactory::getPriceTable($term_id);
                
                    $table->update_meta('pricerunner_allowType4_id',$prData->allowType4ListingallowType4->id);
                    $table->update_meta('pricerunner_data',$prDataJson);
                    $table->update_meta('pricerunner_script_update_at',time());
                    $table->update_meta('pricerunner_script_magic_created_at',time()); 
                    
                    update_field( 'allowType4_afp_id', $term_id, $allowType4_id );
                }
            }
        }
        
	    update_field( 'pricerunner_id', '', $allowType4_id ); 
    }

    private function getPrJson($pr_id) {
        $countryCode = 'DK';

        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, 'https://api.pricerunner.com/public/v2/allowType4/offers/'.$countryCode.'/id?id='.$pr_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        
        $headers = array();
        $headers[] = 'Accept: application/json;charset=UTF-8';
        $headers[] = 'Tokenid: 362b9b37-2f8a-4e3d-9816-5b79eb59ff63';
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);


        if (curl_errno($ch)) {
            return false;
        }


        curl_close($ch);
        

        if ($result) {
            return $result;
        } else {
            return false;
        }
    }
}

$data_transfer_plugin = new DataTransferPlugin();


// allowType2:
/**
 * If you want to create acf repeater and you are not sure how to do it, just provide data in this format
 * [
 *  ...
 *  'repeater_name' => [
 *      0 => [
 *          'repeater_field_1' => 'its value',
 *          'repeater_field_2' => 'its value', ....
 *      ]
 *  ]
 * ]
 * 
 * And then you can loop through your array like this:
 * 
 * foreach ($allowType4_meta as $field_name => $field_value) {
 *    update_field($field_name, $field_value, $allowType4_id);
 * }  
 * 
 * This will automatically fill all the acf fields plus create repeater and its values
 * 
 * NOTE: Your data for acf fields must be wrapped in 'meta' associative array key otherwise wont work
 */
