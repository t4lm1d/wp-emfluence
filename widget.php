<?php

class emfluence_email_signup extends WP_Widget {

  function __construct(){
    /* Widget settings. */
    $widget_ops = array( 'classname' => 'emfluence_email_signup', 'description' => 'Creates an email signup form for your visitors.' );

    /* Widget control settings. */
    $control_ops = array( 'width' => 400, 'id_base' => 'emfluence_email_signup' );

    /* Create the widget. */
    parent::__construct( 'emfluence_email_signup', 'emfluence Marketing Platform Email Signup', $widget_ops, $control_ops );
  }

  /**
   * Provides a static cache for groups
   * @return Emfl_Group[] | NULL
   */
  static function get_groups(){
    static $groups = NULL;

    if( $groups !== NULL ) return $groups;

    $cached = wp_cache_get('emfluence:groups');
    if(!empty($cached)) $groups = $cached;

    if( $groups !== NULL ) return $groups;

    $options = get_option('emfluence_global');
    $api = emfluence_get_api($options['api_key']);
    $groups = array();
    $more = TRUE;
    $page_number = 1;
    while($more){
      $response = $api->groups_search(array(
          'rpp' => 250,
          'page' => $page_number,
          'type' => 'Static'
      ));
      if( !$response || !$response->success ){
        $more = FALSE;
        break;
      }
      foreach( $response->data->records as $group ){
        $groups[$group->groupID] = $group;
      }
      if( !$response->data->paging->nextUrl ){
        $more = FALSE;
      } else {
        ++$page_number;
      }
    }
    wp_cache_set('emfluence:groups', $groups, '', 5*60);
    return $groups;
  }

  /**
   * Validates an email
   *
   * @param string $email
   * @return boolean
   */
  protected function validate_email($email) {
    // Ensure the basic pattern is correct
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return false;
    } else {
      // Test the domain's MX records to avoid more fake domains in the correct pattern.
      list($user, $domain) = explode('@', $email);
      try {
        if( !checkdnsrr($domain, 'MX') ){
          return false;
        }
      } catch( Exception $e ){
        return false;
      }
    }
    return true;
  }

  /**
   * @return string
   */
  protected function get_current_page_url() {
    $pageURL = 'http';
    if (isset($_SERVER["HTTPS"]) AND ($_SERVER["HTTPS"] == "on")) {$pageURL .= "s";}
    $pageURL .= "://";
    if ($_SERVER["SERVER_PORT"] != "80") {
      $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
    } else {
      $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
    }
    return $pageURL;
  }

  /**
   * Validate submitted values against widget settings.
   * Return value is empty if validation passed.
   * @param array $fields as saved in $instance
   * @param array $values sanitized, trimmed submitted values
   * @return string[]
   */
  protected function widget_validate($fields, $values) {
    $defaults = $this->form_get_defaults();
    $messages = array();
    foreach( $fields as $key => $field ){
      if( $field['required'] && ($field['type'] === 'true-false') && !isset($values[$key])) {
        $messages[] = array( 'type' => 'error', 'value' => __( $field['required_message'] ) );
      } elseif( $field['required'] && ($field['type'] !== 'true-false') && empty( $values[$key] ) ){
        $messages[] = array( 'type' => 'error', 'value' => __( $field['required_message'] ) );
      } elseif(empty($values[$key])) continue;
      $field_name = isset($defaults['fields'][$key]) ? $defaults['fields'][$key]['name'] : str_replace(':', '', $field['label']);
      switch($key) {
        case 'email':
          $field['type'] = 'email';
          break;
      }
      switch($field['type']) {
        case 'email':
          if(!$this->validate_email( $values[$key] )) {
            $messages[] = array( 'type' => 'error', 'value' => sprintf(__('%s: Invalid email address.'), $field_name) );
          }
        break;
        case 'number':
          if(!is_numeric($values[$key])) {
            $messages[] = array( 'type' => 'error', 'value' => sprintf(__('%s: Must be numeric.'), $field_name) );
          }
          break;
        case 'date':
          $time = strtotime($values[$key]);
          if(empty($time)) {
            $messages[] = array( 'type' => 'error', 'value' => sprintf(__('%s: Must be a date.'), $field_name) );
          }
          break;
      }
    }
    return $messages;
  }

  /**
   * Wrap the widget's content and return the html to output.
   * @param string $content
   * @return string
   */
  protected function widget_wrap_content($args, $content) {
    extract( $args );

    $title = apply_filters( 'Email Signup', empty( $instance[ 'title' ] ) ? __( 'Email Signup' ) : $instance[ 'title' ] );
    if( $title ) $title = $before_title . '<span>' . $title . '</span>' . $after_title;

    $output = $before_widget . '<form class="mail-form" method="post"><div class="holder"><div class="frame">';
    $output .= $title;
    $output .= $content;
    $output .= '</div></div></form>' . $after_widget;

    return $output;
  }

  function widget( $args, $instance ) {
    /* TODO: Support more than one widget form per page.
     * Currently this plugin works by rendering the form and submitting to the same page.
     * It assumes that the form has been submitted by this instance of the widget.
     * So multiple forms on the same page would try to all process the submission at the same time.
     * We could probably resolve this by identifying the instance ID in the form.
     */
    $values = array();

    if( !empty( $instance[ 'groups' ] ) ) $lists = implode(',', $instance['groups'] );

    // Ensure we can't sign people up without lists
    if( empty($lists) ){
      $output = '<p>' . __('Please select lists visitors may sign up for.') . '</p>' . "\n";
      $output .= '<p>' . __('Powered by emfluence.') . '</p>' . "\n";
      print $this->widget_wrap_content($args, $output);
      return;
    }

    /**
     * Form processing
     */

    if( !empty($_POST) && $_POST['action'] == 'email_signup' ){
      $defaults = $this->form_get_defaults();

      // Set the field values in case there's an error
      foreach( $_POST as $key => $value ){
        $values[$key] = htmlentities( trim( $value ) );
      }

      $messages = $this->widget_validate($instance['fields'], $values);
      array_filter($values);

      if( empty($messages) ){
        // Try to subscribe them
        $options = get_option('emfluence_global');
        $api = emfluence_get_api($options['api_key']);

        $data = array();
        $data['groupIDs'] = !empty($_POST['groups'])? $_POST['groups'] : '';
        $data['originalSource'] = trim( $_POST['source'] );

        // basic contact fields
        foreach($instance['fields'] as $key=>$field) {
          if(empty($values[$key]) || empty($defaults['fields'][$key])) continue;
          $platform_key = $defaults['fields'][$key]['platform'];
          $data[$platform_key] = trim( $_POST[$key] );
        }
        // custom variables
        $data['customFields'] = array();
        for( $i = 1; $i <= 255; $i++ ){
          $field = 'custom' . $i;
          $parameter = 'custom_' . $i;
          if( empty($_POST[$parameter]) ){
            continue;
          }
          $data['customFields'][$field] = array(
            'value' => trim( $_POST[$parameter] ),
          );
        }
        if(empty($data['customFields'])) unset($data['customFields']);
        $result = $api->contacts_save($data);

        if( empty($result) ) {
          wp_mail(
              get_bloginfo('admin_email'),
              'Error sending contact form to emfluence Marketing Platform',
              "Transmission error. \n\nSubmission data: \n" . wp_json_encode($data)
          );
          $messages[] = array('type' => 'error', 'value' => __('An error occurred submitting the form. We have been notified. Please try again later.'));
        } elseif( empty($result->success) ){
          foreach($result->errors as $err) {
            $messages[] = array('type' => 'error', 'value' => __($err));
          }
        } else {
          // SUCCESS!
          if(!empty($instance['success'])) $message = nl2br(wp_kses_post($instance['success']));
          if(empty($message)) {
            ob_start();
            get_template_part('emfluence/success');
            $message = ob_get_clean();
          }
          if(empty($message)) $message = file_get_contents( 'theme/success.php', TRUE);
          print $this->widget_wrap_content($args, $message);
          return;
        } // result of attempted push to platform
      } // passed initial validation
    } // attempted submission

    $output = '';

    // Output all messages
    if( !empty($messages) ){
      $output .= '<ul class="messages">';
      foreach($messages as $message){
        $output .= '<li class="message ' . $message['type'] . '">' . esc_html(__($message['value'])) . '</li>';
      }
      $output .= '</ul>';
    }

    if( !empty($instance['text']) ) {
      $output .= '<div class="lead">' . wpautop(wp_kses_post($instance['text'])) . '</div>';
    }

    $current_page_url = remove_query_arg('sucess', $this->get_current_page_url());
    $output .= '<input type="hidden" name="action" value="email_signup" />' . "\n";
    $output .= '<input type="hidden" name="source" value="' . $current_page_url . '" />' . "\n";
    $output .= '<input type="hidden" name="groups" value="' . implode(',', $instance['groups']) . '" />' . "\n";

    usort($instance['fields'], function($a, $b){
      if($a['order'] == $b['order']){
        return 0;
      }
      return ($a['order'] < $b['order']) ? -1 : 1;
    });
    foreach( $instance['fields'] as $key => $field ){
      if( !$field['display'] ) continue;
      $label = __($field['label']);
      $placeholder = __( str_replace(':', '', $field['label']) );
      $required = $field['required']? 'required' : '';
      $field['type'] = $this->restrict_to_types($field['type']);
      switch( $field['type'] ){
        case 'text':
        case 'email':
        case 'date':
        case 'number':
          $output .= '<div class="field row field-' . $key . '">' . "\n";
          $output .= '<label for="emfluence_' . $key . '">' . esc_html($label) . '';
          if( $field['required'] ){
            $output .= '<span class="required">*</span>';
          }
          $output .= '</label>' . "\n";
          $output .=   '<input placeholder="' . esc_attr($placeholder) . '" type="' . $field['type'] . '" name="' . $field['field_name'] . '" id="emfluence_' . $key . '" value="' . esc_attr($values[$field['field_name']]) . '" ' . $required . ' />' . "\n";
          $output .= '</div>' . "\n";
          break;
        case 'textarea':
          $output .= '<div class="field row field-' . $key . '">' . "\n";
          $output .= '<label for="emfluence_' . $key . '">' . esc_html($label) . '';
          if( $field['required'] ){
            $output .= '<span class="required">*</span>';
          }
          $output .= '</label>' . "\n";
          $output .=   '<textarea placeholder="' . esc_attr($placeholder) . '" name="' . $field['field_name'] . '" id="emfluence_' . $key . '" ' . $required . '>' . esc_html($values[$field['field_name']]) . '</textarea>' . "\n";
          $output .= '</div>' . "\n";
          break;
        case 'true-false':
          $has_value = isset($values[$field['field_name']]);
          $yes_checked = ($has_value && $values[$field['field_name']]) ? 'checked="checked"' : '';
          $no_checked = ($has_value && !$values[$field['field_name']]) ? 'checked="checked"' : '';
          $required = $field['required'] ? '<span class="required">*</span>' : '';
          $output .= '
          <div class="field row field-' . $key . '">
            <label for="emfluence_' . $key . '">' . esc_html($label) . $required . '</label>
            <div class="radio"><input type="radio" name="' . $field['field_name'] . '" value="1" ' . $yes_checked . '>' . __('Yes') . '</div>
            <div class="radio"><input type="radio" name="' . $field['field_name'] . '" value="0" ' . $no_checked . '>' . __('No') . '</div>
          </div>' . "\n";
          break;
          break;
      }
    }

    $output .= '<div class="row actions"><input type="submit" class="submit" value="' . esc_html($instance['submit']) . '" /></div>' . "\n";

    echo $this->widget_wrap_content($args, $output);
    return;
  }

  /**
   * @param $instance
   * @param $groups
   * @return string
   */
  protected function form_template_groups($instance, $groups) {
    $output = '
      <h3>' . __('Groups') . '</h3>
      <div class="groups">
        <div class="filter">
          <p>' . __('Search for any group by name. Contacts will be added to all groups that you select.') . '</p>
          <p>
            <input list="emfluence-emailer-groups-list"/>
            <button type="button" onclick="emfluenceEmailerWidget.groups.add(this)">' . __('Add') . '</button>
          </p>
        </div>
        <div class="selected">' . "\n";
        if( !empty($instance['groups']) ) {
          foreach ($instance['groups'] as $groupID) {
            $group = $groups[$groupID];
            $id = 'groups-' . $this->number . '-' . $groupID;
            $output .= '
              <div>
                <label for="' . $id . '">
                  <input id="' . $id . '" type="checkbox" value="' . $groupID . '" name="groups[]" checked /> ' . $group->groupName . '
                </label>
              </div>';
          }
        }
    $output .= '
        </div>
      </div>';
    return $output;
  }

  /**
   * @param array $instance
   * @return string
   */
  protected function form_template_text_display($instance) {
    $output = '
      <h3>' . __('Text Display') . '</h3>
      <div class="text_display">
        <p>
          <label for="' . $this->get_field_id( 'title' ) . '">' . __('Title') . ':</label>
          <input type="text" id="' . $this->get_field_id( 'title' ) . '" name="' . $this->get_field_name( 'title' ) . '" value="' . $instance['title'] . '" style="width:100%;" />
        </p>
        <p>
          <label for="' . $this->get_field_id( 'text' ) . '">' . __('Text') . ':</label>
          <textarea id="' . $this->get_field_id( 'text' ) . '" name="' . $this->get_field_name( 'text' ) . '" style="width:100%;" >' . $instance['text'] . '</textarea>
        </p>
        <p>
          <label for="' . $this->get_field_id( 'submit' ) . '">' . __('Submit button') . ':</label>
          <input type="text" id="' . $this->get_field_id( 'submit' ) . '" name="' . $this->get_field_name( 'submit' ) . '" value="' . $instance['submit'] . '" style="width:100%;" />
        </p>
        <p>
          <label for="' . $this->get_field_id( 'success' ) . '">' . __('Success message') . ':</label>
          <textarea id="' . $this->get_field_id( 'success' ) . '" name="' . $this->get_field_name( 'success' ) . '" style="width:100%;" >' . $instance['success'] . '</textarea>
          NOTE: If you set the success message here, any theme template file emfluence/success.php will be ignored.
        </p>
      </div>' . "\n";
    return $output;
  }

  /**
   * @param array $fields
   * @return string
   */
  protected function form_template_basic_fields_adder($fields) {
    $output = '
        <div class="basic-fields-adder">
          <p>' . __('Contact field') . '
            <select>';
    foreach($fields as $key=>$field) {
      $output .= '<option value="' . $key . '" data-settings="' . esc_attr(json_encode($field)) . '">' . $field['name'] . '</option>';
    }
    $output .= '
            </select>
            <button type="button" onclick="emfluenceEmailerWidget.fields.add(this)">' . __('Add') . '</button>
          </p>
        </div>';
    return $output;
  }

  /**
   * @param array $defaults
   * @param array $instance
   * @return string
   */
  protected function form_template_basic_fields($defaults, $instance) {
    $output = '
        <h3>' . __('General Contact Fields') . '</h3>
        <div class="basic_contact_fields">
            ';
    $output .= $this->form_template_basic_fields_adder($defaults['fields']);
    foreach( $defaults['fields'] as $key => $field ) {
      if(empty($instance['fields'][$key]['display'])) continue;
      $output .= $this->form_template_field($defaults['fields'][$key]['name'], $key, $instance['fields'][$key]);
    }
    $output .= '
            <div class="basic_contact_field_template" style="display: none;">
            ' . $this->form_template_field(
            'CONTACT_FIELD_NAME',
            'CONTACT_FIELD_KEY',
            array(
                'name' => 'CONTACT_FIELD_NAME',
                'display' => 1,
                'required' => 0,
                'required_message' => 'CONTACT_FIELD_REQUIRED_MESSAGE',
                'label' => 'CONTACT_FIELD_LABEL',
                'order' => 'CONTACT_FIELD_ORDER',
                'type' => 'text'
            )) . '
          </div>
        </div>
      ';
    return $output;
  }

  /**
   * @return string
   */
  protected function form_template_custom_variables_adder() {
    $output = '
        <div class="custom-variable-adder">
          <p>' . __('Variable number') . '
            <input type="number" min="1" max="250" step="1"/>
            <button type="button" onclick="emfluenceEmailerWidget.variables.add(this)">' . __('Add') . '</button>
          </p>
        </div>';
    return $output;
  }

  /**
   * @param array $defaults
   * @param array $instance
   * @return string
   */
  protected function form_template_custom_variables($defaults, $instance) {
    $output = '
        <h3>' . __('Custom Variables') . '</h3>
        <div class="custom_variables">
            ' . $this->form_template_custom_variables_adder();
    foreach( $instance['fields'] as $key => $field ) {
      if(isset($defaults['fields'][$key])) continue; // so we're only dealing with custom fields
      if(empty($instance['fields'][$key]['display'])) continue;
      $variable_number = intval(str_replace('custom_', '', $key));
      $name = sprintf(__('Variable %d'), $variable_number);
      $output .= $this->form_template_field($name, $key, $instance['fields'][$key]);
    }
    $output .= '
          <div class="custom_variable_template" style="display: none;">
            ' . $this->form_template_field(
            'Variable CUSTOM_VARIABLE_NUMBER',
            'custom_CUSTOM_VARIABLE_NUMBER',
            array(
                'name' => 'Variable CUSTOM_VARIABLE_NUMBER',
                'display' => 1,
                'required' => 0,
                'required_message' => 'Custom CUSTOM_VARIABLE_NUMBER is required.',
                'label' => 'Custom CUSTOM_VARIABLE_NUMBER:',
                'order' => 6,
            )) . '
          </div>
        </div>
      ';
    return $output;
  }

  /**
   * @param string $name Like 'First Name'
   * @param string $key Like 'first_name'
   * @param array $field Field definition. Like
   *  array(
   *    'name' => __('First Name'),
   *    'display' => 1,
   *    'required' => 1,
   *    'required_message' => __('First name is required.'),
   *    'label' => __('First Name:'),
   *    'order' => 1,
   *    )
   * @return string
   */
  protected function form_template_field($name, $key, $field) {
    $available_types = $this->form_get_allowed_types();

    $display_input = array(
        'id' => $this->get_field_id( $key . '_display' ),
        'name' => $this->get_field_name(  $key . '_display' ),
        'checked' => $field['display'] == 1? 'checked="checked"' : '',
        'disabled' => '',
    );
    $required_input = array(
        'id' => $this->get_field_id( $key . '_required' ),
        'name' => $this->get_field_name(  $key . '_required' ),
        'checked' => $field['required'] == 1? 'checked="checked"' : '',
        'disabled' => '',
    );
    $required_message_input = array(
        'id' => $this->get_field_id( $key . '_required_message' ),
        'name' => $this->get_field_name(  $key . '_required_message' ),
        'value' => $field['required_message'],
    );
    $label_input = array(
        'id' => $this->get_field_id( $key . '_label' ),
        'name' => $this->get_field_name(  $key . '_label' ),
        'value' => $field['label'],
    );
    $order_input = array(
        'id' => $this->get_field_id( $key . '_order' ),
        'name' => $this->get_field_name(  $key . '_order' ),
        'value' => $field['order'],
    );
    $type_input = array(
        'id' => $this->get_field_id( $key . '_type' ),
        'name' => $this->get_field_name(  $key . '_type' ),
        'value' => empty($field['type']) ? 'text' : $this->restrict_to_types($field['type']),
        'options' => array(),
        'disabled' => '',
    );

    if($key == 'email') {
      $display_input['disabled'] = 'disabled="disabled"';
      $required_input['disabled'] = 'disabled="disabled"';
      $type_input['disabled'] = 'disabled="disabled"';
      $type_input['value'] = 'email';
    }

    foreach($available_types as $type) {
      $selected = ($type == $type_input['value'] ? 'selected="selected"' : '');
      $type_input['options'][] = '<option ' . $selected . '>' . $type . '</option>';
    }

    $output = '
        <div class="contact-field" data-variable-key="' . $key . '">
          <p class="heading">
            <label class="heading" for="' . $display_input['id'] . '">
              <input type="checkbox" id="' . $display_input['id'] . '" name="' . $display_input['name'] . '" value="1" ' . $display_input['checked'] . ' ' . $display_input['disabled'] . ' />
              ' . __($name) . '
            </label>
            <label for="' . $required_input['id'] . '">
              <input type="checkbox" id="' . $required_input['id'] . '" name="' . $required_input['name'] . '" value="1" ' . $required_input['checked'] . ' ' . $display_input['disabled'] . ' />
              ' . __('Required') . '
            </label>
            <label for="' . $order_input['id'] . '">
              <input type="text" id="' . $order_input['id'] . '" name="' . $order_input['name'] . '" value="' . $order_input['value'] . '" class="order" size="2" />
              ' . __('Order') . '
            </label>
          </p>
          <p>
            <label for="' . $required_message_input['id'] . '">' . __('Required Message') . '</label>
            <input type="text" id="' . $required_message_input['id'] . '" name="' . $required_message_input['name'] . '" value="' . $required_message_input['value'] . '" style="width:100%;" />
          </p>
          <p>
            <label for="' . $label_input['id'] . '">' . __('Label') . '</label>
            <input type="text" id="' . $label_input['id'] . '" name="' . $label_input['name'] . '" value="' . $label_input['value'] . '" style="width:100%;" />
          </p>
          <p>
            <label for="' . $type_input['id'] . '">' . __('Type') . '</label>
            <select id="' . $type_input['id'] . '" name="' . $type_input['name'] . '" ' . $type_input['disabled'] . '>
              ' . implode('', $type_input['options']) .'
            </select>
          </p>
        </div>
        ';
    return $output;
  }

  /**
   * Get a list of allowed field types
   * @return string[]
   */
  protected function form_get_allowed_types() {
    return array(
        'text', 'textarea', 'email', 'date', 'number', 'true-false'
    );
  }

  /**
   * Restrict a field type to the allowed types
   * @param string $type
   * @return string
   */
  protected function restrict_to_types($type) {
    $allowed = $this->form_get_allowed_types();
    return (array_search($type, $allowed) === FALSE ? 'text' : $type);
  }

  /**
   * @return array
   */
  protected function form_get_defaults() {
    $defaults = array(
        'title' => __('Email Signup'),
        'text' => '',
        'groups' => array(),
        'custom_fields' => array(),
        'submit' => __('Signup'),
        'fields' => array(),
    );

    $contact_field_defaults = array(
        'first_name' => array(
            'name' => __('First Name'),
            'required_message' => __('First name is required.'),
            'label' => __('First Name:'),
            'type' => 'text',
            'platform' => 'firstName'
        ),
        'last_name' => array(
            'name' => __('Last Name'),
            'required_message' => __('Last name is required.'),
            'label' => __('Last Name:'),
            'type' => 'text',
            'platform' => 'lastName'
        ),
        'title' => array(
            'name' => __('Title'),
            'required_message' => __('Title is required.'),
            'label' => __('Title:'),
            'type' => 'text',
            'platform' => 'title'
        ),
        'company' => array(
            'name' => __('Company'),
            'required_message' => __('Company is required.'),
            'label' => __('Company:'),
            'type' => 'text',
            'platform' => 'company'
        ),
        'email' => array(
            'name' => 'Email',
            'display' => 1,
            'required' => 1,
            'required_message' => 'Email address is required.',
            'label' => 'Email:',
            'type' => 'email',
            'platform' => 'email'
        ),
        'address1' => array(
            'name' => 'Address 1',
            'required_message' => 'Address 1 is required.',
            'label' => 'Address 1:',
            'type' => 'text',
            'platform' => 'address1'
        ),
        'address2' => array(
            'name' => 'Address 2',
            'required_message' => 'Address 2 is required.',
            'label' => 'Address 2:',
            'type' => 'text',
            'platform' => 'address2'
        ),
        'city' => array(
            'name' => 'City',
            'required_message' => 'City is required.',
            'label' => 'City:',
            'type' => 'text',
            'platform' => 'city'
        ),
        'state' => array(
            'name' => 'State',
            'required_message' => 'State is required.',
            'label' => 'State:',
            'type' => 'text'
        ),
        'zipCode' => array(
            'name' => 'Zip Code',
            'required_message' => 'Zip Code is required.',
            'label' => 'Zip Code:',
            'type' => 'text',
            'platform' => 'zipCode'
        ),
        'country' => array(
            'name' => 'Country',
            'required_message' => 'Country is required.',
            'label' => 'Country:',
            'type' => 'text',
            'platform' => 'country'
        ),
        'phone' => array(
            'name' => 'Phone',
            'required_message' => 'Phone is required.',
            'label' => 'Phone:',
            'type' => 'text',
            'platform' => 'phone'
        ),
        'fax' => array(
            'name' => 'Fax',
            'required_message' => 'Fax is required.',
            'label' => 'Fax:',
            'type' => 'text',
            'platform' => 'fax'
        ),
        'dateofbirth' => array(
            'name' => 'Date of birth',
            'required_message' => 'Date of birth is required.',
            'label' => 'Date of birth:',
            'type' => 'date',
            'platform' => 'dateofbirth'
        ),
        'notes' => array(
            'name' => 'Notes',
            'required_message' => 'Notes is required.',
            'label' => 'Notes:',
            'type' => 'text',
            'platform' => 'notes'
        ),
        'memo' => array(
            'name' => 'Memo',
            'required_message' => 'Memo is required.',
            'label' => 'Memo:',
            'type' => 'textarea',
            'platform' => 'memo'
        )
    );

    foreach($contact_field_defaults as $name=>$field) {
      if(empty($field['display'])) $field['display'] = 0;
      if(empty($field['required'])) $field['required'] = 0;
      if(empty($field['order'])) $field['order'] = 1;
      $defaults['fields'][$name] = $field;
    }
    return $defaults;
  }

  public function form( $instance ) {
    $options = get_option('emfluence_global');
    $api = emfluence_get_api($options['api_key']);
    $ping = $api->ping();
    if( !$ping || !$ping->success ){
      $output = '<h3>' . __('Authentication Failed') . '</h3>';
      $output .= '<p>' . __('Please check your api key to continue.') . '</p>';
      print $output;
      return;
    }

    $defaults = $this->form_get_defaults();
    $instance = wp_parse_args( (array) $instance, $defaults );
    $groups = emfluence_email_signup::get_groups();

    $output = $this->form_template_text_display($instance);
    $output .= $this->form_template_groups($instance, $groups);
    $output .= $this->form_template_basic_fields($defaults, $instance);
    $output .= $this->form_template_custom_variables($defaults, $instance);

    // Output the datalist for groups just once
    if( intval($this->number) == 0  ) {
      $output .= '<datalist id="emfluence-emailer-groups-list" style="display: none;">';
      foreach ($groups as $group) {
        $output .= '<option>' . $group->groupName . ' [' . $group->groupID . ']' . '</option>';
      }
      $output .= '</datalist>';
    }

    print '<div class="wp-emfluence">' . $output . '</div>';
  }

  /**
   * Update the widget settings.
   */
  function update( $new_instance, $old_instance ) {
    $instance = $new_instance;

    $instance['fields'] = array();

    // Force certain settings for email field
    $instance['email_display'] = $instance['email_required'] = '1';
    $instance['email_type'] = 'email';

    // Basic contact fields
    $defaults = $this->form_get_defaults();
    foreach($defaults['fields'] as $field_key=>$default_field) {
      if(empty($instance[$field_key . '_display'])) continue;
      $instance['fields'][$field_key] = array(
          'field_name' => $field_key,
          'display' => 1,
          'required' => $instance[$field_key . '_required'] == 1 ? 1  : 0,
          'required_message' => !empty($instance[$field_key . '_required_message'])? stripslashes(trim($instance[$field_key . '_required_message'])) : $field_key . ' address is required.',
          'label' => !empty($instance[$field_key . '_label'])? stripslashes(trim($instance[$field_key . '_label'])) : $default_field['label'],
          'order' => is_numeric($instance[$field_key . '_order'])? $instance[$field_key . '_order'] : 5,
          'type' => empty($instance[$field_key . '_type']) ? 'text' : $this->restrict_to_types($instance[$field_key . '_type'])
      );
    }
    // Unset template fields.
    $template_prefix = 'CONTACT_FIELD_KEY';
    foreach($instance as $field_key=>$field_val) {
      if(strpos($field_key, $template_prefix) !== 0) continue;
      unset($instance[$field_key]);
    }

    // Custom variables
    foreach($new_instance as $field_key=>$field_val) {
      if(strpos($field_key, 'custom_') !== 0) continue;
      $is_custom_display = (
          strrpos($field_key, '_display') === (strlen($field_key) - strlen('_display'))
          );
      if(!$is_custom_display) continue;

      // unset template fields.
      $template_prefix = 'custom_CUSTOM_VARIABLE_NUMBER';
      $is_template = (strpos($field_key, $template_prefix) === 0);
      if($is_template) {
        foreach($instance as $field_key_x=>$field_val_x) {
          if(strpos($field_key_x, $template_prefix) !== 0) continue;
          unset($instance[$field_key_x]);
        }
        continue;
      }

      $variable_number = intval(str_replace('custom_', '', $field_key));
      if(empty($variable_number)) continue;
      $key_prefix = 'custom_' . $variable_number;
      $instance['fields'][$key_prefix] = array(
          'field_name' => $key_prefix,
          'display' => 1,
          'required' => $new_instance[$key_prefix . '_required'] == 1? 1  : 0,
          'required_message' => !empty($new_instance[$key_prefix . '_required_message'])? stripslashes(trim($new_instance[$key_prefix . '_required_message'])) : 'Custom ' . $variable_number . ' is required.',
          'label' => !empty($new_instance[$key_prefix . '_label'])? stripslashes(trim($new_instance[$key_prefix . '_label'])) : 'Custom ' . $variable_number . ':',
          'order' => is_numeric($new_instance[$key_prefix . '_order'])? $new_instance[$key_prefix . '_order'] : 6,
          'type' => empty($instance[$key_prefix . '_type']) ? 'text' : $this->restrict_to_types($instance[$key_prefix . '_type'])
        );
    }

    // Unfortunately, these don't come through $new_instance
    $instance['groups'] = array_values($_POST['groups']);

    // Clean up the free-form areas
    $instance['title'] = stripslashes($new_instance['title']);
    $instance['text'] = stripslashes($new_instance['text']);
    $instance['submit'] = stripslashes($new_instance['submit']);
    $instance['success'] = stripslashes($new_instance['success']);

    // If the current user isn't allowed to use unfiltered HTML, filter it
    if ( !current_user_can('unfiltered_html') ) {
      $instance['title'] = strip_tags($new_instance['title']);
      $instance['text'] = strip_tags($new_instance['text']);
      $instance['submit'] = strip_tags($new_instance['submit']);
      $instance['success'] = strip_tags($new_instance['success']);
      foreach($instance['fields'] as &$field){
        $field['label'] = strip_tags($field['label']);
        $field['required_message'] = strip_tags($field['required_message']);
      }
    }

    return $instance;
  }
}
