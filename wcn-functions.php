<?php

if( !function_exists('pri') ) {
    function pri($data){
        echo '<pre>';print_r($data);echo '</pre>';
    }
}

class wcn_functions{

    public static function is_pro() {
        if ( is_dir( dirname(__FILE__).'/inc/pro' ) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get notification data of product
     */
    public static function get_product_notification_data( $product_id, $with_userdata = false, $with_productdata = false ) {
        global $wpdb;
        $notification_table = $wpdb->prefix.'wcn_notification';
        $user_table = $wpdb->prefix.'users';

        if( $with_userdata ) {
            $result = $wpdb->get_results( "SELECT *
FROM $user_table
RIGHT JOIN $notification_table
ON $user_table.ID = $notification_table.user_id
ORDER BY $notification_table.ID;" );
        } else {
            $result = $wpdb->get_results( "SELECT * FROM ".$wpdb->prefix."wcn_notification WHERE product_id = ".$product_id." GROUP BY notification_for" );
        }

        return $result;
    }


    /**
     * Add user notification
     * to the product
     * meta
     * @param array $opt
     * @return bool
     */
    public static function add_notification_to_product( $opt = array() ){

        global $wpdb;
        $default = array(
            'product_id' => '',
            'user_id' => '',
            'notification_for' => '',
            'notification_type' => '',
            'status' => ''
        );

        $opt = array_merge( $default, $opt );
        extract( $opt );
        $result = 0;

        $search_statuses = apply_filters( 'wcn_check_count_to_add_notification', array( 'approved' ) );

        //check if user has set the notification
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM ".$wpdb->prefix."wcn_notification WHERE product_id = ".$product_id." AND user_id = ".$user_id."
         AND notification_for = '".$notification_for."' AND notification_type = '".$notification_type."' AND status IN ( '".implode( "','", $search_statuses )."' )" );

        if( !$count ) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO ".$wpdb->prefix."wcn_notification ( id, product_id, user_id, notification_for, notification_type, status, time )
 VALUES (NULL, %d, %d, %s, %s, %s, %s);",
                    $product_id, $user_id, $notification_for, $notification_type, $status, date('Y-m-d H:i:s')
                )
            );
        }

        return $result;
    }


    /**
     * Remove notification of a user
     * from array from wcn_product_notification
     * @param array $opt
     */
    public static function remove_notification_from_product( $opt = array() ) {

        global $wpdb;
        $notification_table = $wpdb->prefix.'wcn_notification';

        $default = array(
            'product_id' => '',
            'user_id' => '',
            'notification_for' => '',
            'notification_type' => '',
            'not_status' => array()
        );
        $opt = array_merge( $default, $opt );
        extract( $opt, EXTR_PREFIX_SAME, 'wcn' );
        $result = 0;

        $return = $wpdb->query(
            $wpdb->prepare(
                "
                DELETE FROM $notification_table
		 WHERE product_id = %d
		 AND user_id = %d
		 AND notification_for = %s
		 AND notification_type = %s
		 AND status NOT IN ( '". implode( "','" , $statuses ) ."' )
		",
                $product_id , $user_id, $notification_for, $notification_type
            )
        );


        if( $return ) {
            $result = 1;
        }

        return $result;
    }


    /**
     * Send notification to
     * the waiting customer
     */
    public static function send_notification_to_customers( $opt = array() ){

        $wcn_settings = get_option( 'wcn_settings');

        $default = array(
            'notification_for' => '', // for availablity/discount
            'notification_type' => '', // type of notification. Email/sms etc
            'data' => '', // notification data array,
            'product_title' => '',
            'product_link' => ''
        );

        $opt = array_merge( $default, $opt );
        extract( $opt );

        $msg_search = array( '%product_link%' );
        $msg_replace = array( $product_link );

        if( !is_array( $data ) ) return;

        switch( $notification_type ) {

            case 'email' :
                $sent_email_row_id = array();

                $email_to = '';
                $headers = "From: " . strip_tags( get_option( 'admin_email' ) ) . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";

                if( $notification_for == 'availablity' ) {
                    $subject = 'The product '.$product_title.' is now on store !';
                    $message = str_replace( $msg_search, $msg_replace, $wcn_settings['availablity']['notification_by']['mail']['body'] );
                } elseif ( $notification_for == 'discount' ) {
                    $subject = 'The product '.$product_title.' is now in discount !';
                    $message = str_replace( $msg_search, $msg_replace, $wcn_settings['discount']['notification_by']['mail']['body'] );
                }
                
                foreach( $data as $key => $noti_array ) {
                    $noti_array = (array)$noti_array;
                    if( $noti_array['notification_for'] == $notification_for
                        && $noti_array['notification_type'] == $notification_type
                        && $noti_array['status'] == 'approved'
                    ) {
                        $email_to .= $noti_array['user_email'];
                        $sent_email_row_id[] = $noti_array['id'];
                    }
                }

                //send the mail now
                if( !empty( $email_to ) ) {
                    if( mail( trim( $email_to, ',' ), $subject, $message, $headers ) ) {
                        return $sent_email_row_id;
                    }
                }

                break;
        }

        return $sent_email_row_id;

    }


    /**
     * Change status
     */
    public static function change_notification_status( $arg = array() ){

        global $wpdb;

        $notification_table = $wpdb->prefix.'wcn_notification';

        $param = array(
            'case' => array(
                'status' => 'id'
            ),
            'where_field' => array(
                'id' => array(),
                'status' => ''
            ),
            'update_field' => array(
                'status' => array()
            )
        );

        $arg = array_merge( $param, $arg );

        $update_fields = array_keys( $arg['update_field'] );

        $set_str = ' SET ';
        $when_str = '';

        foreach( $update_fields as $field ) {
            $set_str .= $field." = CASE ".$arg['case'][$field];

            foreach( $arg['where_field'][$arg['case'][$field]] as $key => $val ) {
                if( is_array( $arg['update_field'][$field] ) ) {
                    $when_str .= " WHEN ".$val." THEN '".$arg['update_field'][$field][$val]."' ";
                } else {
                    $when_str .= " WHEN ".$val." THEN '".$arg['update_field'][$field]."' ";
                }

            }
            $when_str .= ' END ';
            $set_str .= $when_str;
            $when_str = '';
        }

        $where_str = ' WHERE ';

        foreach( $arg['where_field'] as $where_key => $where_val ){

            if( is_array( $where_val ) ) {
                $where_str .= " ".$where_key." IN ( ".trim( implode( ',',$where_val ) , ',' )." ) AND ";
            } else {
                $where_str .= " ".$where_key." = '".$where_val."' AND ";
            }

        }

        $where_str .= ' 1';

        $wpdb->query(
            //$wpdb->prepare(
            "UPDATE $notification_table ".
            $set_str.$where_str
            //)
        );
    }


}