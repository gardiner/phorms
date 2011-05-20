<?php

//Search for "function load_strings" to find error messages

abstract class Phorm_Phorm
{
	private $method;
	private $multi_part = false;
	public $bound = false;
	private $data;
	private $fields = array();
	private $errors = array();
	private $clean;
	private $valid;
	public $lang;

	public function __construct($method='post', $multi_part=FALSE, $data=array(), $lang='en')
	{
		$this->multi_part = $multi_part;

		if( $this->multi_part && $method != 'post' )
		{
			$method = 'post';
			throw new Exception('Multi-part form method changed to POST.', E_USER_WARNING);
		}

		// Set up fields
		$this->define_fields();
		$this->fields = $this->find_fields();

		// Find submitted data, if any
		$method = strtolower($method);
		$this->method = $method;
		$user_data = ($this->method == 'post') ? $_POST : $_GET;

		// Determine if this form is bound (depends on defined fields)
		$this->bound = $this->check_if_bound($user_data);

		// Merge user data over the default data (if any)
		$this->data = array_merge($data, $user_data);

		// Set the fields' data
		$this->set_data();
		$this->lang = new Phorm_Language($lang);
	}

	abstract protected function define_fields();

	private function check_if_bound(array $data)
	{
		foreach( $this->fields as $name => $field )
		{
			if( array_key_exists($name, $data) || ($this->multi_part && array_key_exists($name, $_FILES)) )
			{
				return TRUE;
			}
		}
		return FALSE;
	}

	private function find_fields()
	{
		$found = array();
		foreach( array_keys(get_object_vars($this)) as $name )
		{
			if( $this->$name instanceof Phorm_Field )
			{
				$name = htmlentities($name);
				$id = sprintf('id_%s', $name);

				$this->$name->set_attribute('id', $id);
				$this->$name->set_attribute('name', ($this->$name->multi_field) ? sprintf('%s[]', $name) : $name);

				$found[$name] = & $this->$name;
			}
		}
		return $found;
	}

	private function set_data()
	{
		foreach( $this->fields as $name => &$field )
		{
			if( array_key_exists($name, $this->data) )
			{
				$field->set_value($this->data[$name]);
			}
		}
	}

	public function cleaned_data($reprocess=FALSE)
	{
		if( !$this->bound && !$this->is_valid() )
		{
			return NULL;
		}

		if( !is_array($this->clean) || $reprocess )
		{
			$this->clean = array();
			foreach( $this->fields as $name => &$field )
			{
				$this->clean[$name] = $field->get_value();
			}
		}

		return $this->clean;
	}
	
	public function fields()
	{
		return $this->fields;
	}
	
	public function has_errors()
	{
		return !empty($this->errors);
	}

	public function get_errors()
	{
		foreach( $this->fields as $name => &$field )
		{
			if( $errors = $field->get_errors() )
			{
				foreach($errors as $error)
				{
					$this->errors[$name] = array($error[0], $error[1]);
				}
			}
		}
		return $this->errors;
	}
	
	public function display_errors($prefix = '', $suffix = '')
	{	
		$nested_errors = $this->get_errors();
		foreach ($nested_errors as $field_name => $field_error)
		{
			echo $prefix;
			echo $this->$field_name->label(false) . ': ' . $field_error[1];
			echo $suffix;
		}
	}

	public function is_valid($reprocess=FALSE)
	{
		if( $reprocess || is_null($this->valid) )
		{
			if( $this->bound )
			{
				$this->valid = TRUE;
				foreach( $this->fields as $name => &$field )
				{
					if( !$field->is_valid($reprocess) )
					{
						$this->valid = FALSE;
					}
				}

				// Set up the errors array.
				$this->get_errors();
			}
		}

		return $this->valid;
	}

	public function open($target=NULL, $attributes=NULL)
	{
		if( is_null($target) )
		{
			$target = $_SERVER['PHP_SELF'];
		}

		return sprintf('<form method="%s" action="%s"%s id="%s">',
			$this->method,
			htmlentities((string) $target),
			($this->multi_part) ? ' enctype="multipart/form-data"' : '',
			strtolower(get_class($this))
		)."\n";
	}

	public function close()
	{
		return "</form>\n";
	}

	public function buttons($buttons = array())
	{
		global $phorms_tr;

		if( empty($buttons) || !is_array($buttons) )
		{
			$reset = new Phorm_Widget_Reset();
			$submit = new Phorm_Widget_Submit();
			return $reset->html($phorms_tr['buttons_reset'], array( 'class' => 'phorms-reset' )).
				"\n".$submit->html($phorms_tr['buttons_validate'], array( 'class' => 'phorms-submit' ));
		}
		else
		{
			$out = array();
			foreach( $buttons as $button )
			{
				$out[] = $button[1]->html($button[0]);
			}
			return implode("\n", $out);
		}
	}

	public function __toString()
	{
		return $this->as_labels();
	}

	public function as_labels()
	{
		$elts = array();
		foreach( $this->fields as $name => $field )
		{

			$label = $field->label();
			if(!empty($label))
			{
				$elts[] = '<div class="phorm_element">';
				$elts[] = $label;
				$elts[] = $field;
				$elts[] = '</div>';
			}
			else
			{
				$elts[] = strval($field);
			}
		}
		return implode("\n", $elts);
	}

	public function as_table($alt=FALSE, $template='')
	{
		if(empty($template))
		{
			$template[] = '<tr class="phorm_table_row%odd%">';
			$template[] = '<td class="phorm_table_cell_label">%label%</td>';
			$template[] = '<td class="phorm_table_cell_field">%field%%errors%</td>';
			$template[] = '<td class="phorm_table_help_text">%help_text%</td>';
			$template[] = '</tr>';
			$template = implode("\n", $template);
		}

		$out[] = '<table class="phorm_table">';
		$out[] = '<tbody>';
		$count = 0;
		foreach( $this->fields as $name => $field )
		{
			$odd = '';
			if($alt)
			{
				$odd = ($count%2) ? '' : ' phorm_odd_row';
				$count++;
			}
			$out[] = str_replace(
				array('%odd%','%label%','%field%','%errors%','%help_text%'),
				array($odd, $field->label(FALSE), $field->html(), $field->errors(), $field->help_text()),
				$template
			);
		}
		$out[] = '</tbody>';
		$out[] = '</table>';
		return implode("\n", $out);
	}

	public function display($target = NULL, $js = TRUE)
	{
		echo $this->open($target).$this.$this->buttons().$this->close($js);
	}

}

class Phorm_Language
{
	private $lang = array();
	private $fallback = array();

	public function __construct($lang='en')
	{
		$english = $this->load_strings('en');
		if($lang!=='en')
		{
			$this->lang = $this->load_strings($lang);
			$this->fallback = $english;
		}
		else
		{
			$this->lang = $english;
		}
	}

	public function __get($name)
	{
		if(substr($name, 0, 2) == 'a:')
		{
			$args = unserialize( $name );
			$name = array_shift($args);

		}

		if(!strstr($name,' '))
		{
			if (isset($this->lang[$name]))
			{
				$name = $this->lang[$name];
			}
			elseif( isset($this->fallback[$name]) )
			{
				$name = $this->fallback[$name];
			}
			else
			{
				throw new Exception('Phorms could not retrieve string for "'.$name.'", please review your code.');
			}
		}

		if(!empty($args))
		{
			array_unshift($args, $name);
			return call_user_func_array( 'sprintf', $args );
		}

		return $name;

	}

	private function load_strings($code)
	{
		$lang = array();
		$lang['field_file_toolarge'] = 'The file sent was too large.';
		$lang['field_file_uploaderror'] = 'There was an error uploading the file; please try again.';
		$lang['field_file_notsent'] = 'The file was not sent; please try again.';
		$lang['field_file_syserror'] = 'There was a system error during upload; please contact the webmaster (error number %s).';
		$lang['field_file_badtype'] = 'Files of type %s are not accepted.';
		$lang['field_file_sizelimit'] = 'Files are limited to %s bytes.';
		$lang['field_invalid_text_sizelimit'] = 'Must be fewer than %s characters in length.';
		$lang['field_invalid_integer'] = 'Must be a whole number.';
		$lang['field_invalid_integer_sizelimit'] = 'Must be a whole number with fewer than %s digits.';
		$lang['field_invalid_alpha'] = 'Must only contain alphabetic characters.';
		$lang['field_invalid_alphanum'] = 'Must only contain alphabetic or numeric characters.';
		$lang['field_invalid_decimal'] = 'Invalid decimal value.';
		$lang['field_invalid_dropdown'] = 'Invalid selection.';
		$lang['field_invalid_url'] = 'Invalid URL.';
		$lang['field_invalid_email'] = 'Invalid email address.';
		$lang['field_invalid_datetime_format'] = 'Date/time format not recognized.';
		$lang['field_invalid_multiplechoice_badformat'] = 'Invalid selection.';
		$lang['buttons_validate'] = 'Validate';
		$lang['buttons_reset'] = 'Clear form';
		$lang['validation_required'] = 'This field is required.';
		return $lang;
	}
}

class Phorm_ValidationError extends Exception
{

}

abstract class Phorm_Field
{

	public $label;
	private $value;
	private $validators;
	private $attributes;
	private $errors;
	private $imported;
	private $help_text = '';
	public $multi_field = false;
	private $valid;

	public function __construct($label, array $validators=array(), array $attributes=array(), $lang='en')
	{
		if( !isset($attributes['class']) )
		{
			$attributes['class'] = strtolower(get_class($this));
		}
		else
		{
			$attributes['class'] .= ' '.strtolower(get_class($this));
		}
		
		$this->label = (string) $label;
		$this->attributes = $attributes;
		$this->validators = $validators;
		$this->lang = new Phorm_Language($lang);
	}

	public function set_value($value)
	{
		$this->value = $value;
	}

	public function get_value()
	{
		return $this->imported;
	}

	public function get_raw_value()
	{
		return $this->value;
	}

	public function set_attribute($key, $value)
	{
		$this->attributes[$key] = $value;
	}

	public function get_attribute($key)
	{
		if( array_key_exists($key, $this->attributes) )
		{
			return $this->attributes[$key];
		}
		return null;
	}

	public function get_errors()
	{
		return $this->errors;
	}

	public function add_error($error)
	{
		$this->errors[] = $error;
	}

	public function help_text($text='')
	{
		if( !empty($text) )
		{
			$this->help_text = $text;
		}
		elseif( !empty($this->help_text) )
		{
			return '<span class="phorm_help">'.htmlentities($this->help_text).'</span>';
		}
	}

	public function label($tag=TRUE)
	{
		if($tag)
		{
			return sprintf('<label for="%s">%s</label>', (string) $this->get_attribute('id'), $this->label);
		}
		return $this->label;
	}

	public function html()
	{
		$widget = $this->get_widget();
		return $widget->html($this->value, $this->attributes);
	}

	public function errors($tag=TRUE)
	{
		$elts = array();
		if( is_array($this->errors) && !empty($this->errors) )
		{
			foreach( $this->errors as $valid => $error )
			{
				if($tag)
				{
					$elts[] = sprintf('<div class="validation-advice" id="advice-%s-%s">%s</div>', $error[0], (string) $this->get_attribute('id'), $this->lang->{$error[1]});
				}
				else
				{
					$elts[] = $this->lang->{$error[1]};
				}
			}
			return implode("\n", $elts);
		}
		return (empty($elts))?'':$elts;
	}

	public function __toString()
	{
		return $this->html().$this->help_text.$this->errors();
	}

	public function is_valid($reprocess=false)
	{
		if( $reprocess || is_null($this->valid) )
		{
			// Pre-process value
			$value = $this->prepare_value($this->value);

			$this->errors = array();
			$v = $this->validators;

			foreach( $v as $k => $f )
			{
				try
				{
					if ($f == 'required') { //special case -- available to all field types, and $this->validate() isn't even called if value is empty
						$this->validate_required_field($value);
					} else {
						call_user_func($f, $value);
					}
				}
				catch( Phorm_ValidationError $e )
				{
					$rule_name = is_array($f) ? $f[1] : $f; //handles both string (function name) and array (instance, function name)
					$this->errors[] = array( $rule_name, $this->lang->{$e->getMessage()} );
				}
			}

			if( $value !== '' )
			{
				try
				{
					$this->validate($value);
				}
				catch( Phorm_ValidationError $e )
				{
					$this->errors[] = array( strtolower(get_class($this)), $this->lang->{$e->getMessage()} );
				}
			}

			if( $this->valid = empty($this->errors) )
			{
				$this->imported = $this->import_value($value);
			}
		}
		return $this->valid;
	}

	public function prepare_value($value)
	{
		return ( get_magic_quotes_gpc() ) ? stripslashes($value) : $value;
	}

	abstract protected function get_widget();

	public function validate($value)
	{
		return filter_var($value, FILTER_SANITIZE_STRING);
	}

	abstract public function import_value($value);
	
	public function validate_required_field($value)
	{
		if ($value == '' || is_null($value))
		{
			throw new Phorm_ValidationError('validation_required');
		}
	}
}

class Phorm_Fieldset
{
	public $id;
	public $name;
	public $label;
	public $field_names = array( );

	function __construct($name, $label, $field_names=array( ))
	{
		$this->id = 'id_'.$name;
		$this->name = $name;
		$this->label = $label;
		$this->field_names = $field_names;
	}

}

class Phorm_Widget
{
	protected function serialize_attributes(array $attributes=array())
	{
		if(empty($attributes))
		{
			return '';
		}

		$attr = array();
		foreach( $attributes as $key => $val )
		{
			$attr[] = $key.'="'.$val.'"';
		}
		return ' '.implode(' ', $attr).' ';
	}

	protected function serialize($value, array $attributes=array())
	{
		return '<input value="'.$value.'"'.$this->serialize_attributes($attributes).'/>';
	}

	protected function clean_string($str)
	{
		return htmlentities((string) $str);
	}

	public function html($value, array $attributes=array())
	{
		$value = $this->clean_string($value);

		foreach( $attributes as $key => $val )
		{
			$attributes[$this->clean_string($key)] = $this->clean_string($val);
		}

		return $this->serialize($this->clean_string($value), $attributes);
	}

}

abstract class Phorm_FieldsetPhorm extends Phorm_Phorm
{

	public function __construct($method='get', $multi_part=false, $data=array( ))
	{
		parent::__construct($method, $multi_part, $data);
		$this->define_fieldsets();
	}

	public function as_labels()
	{
		$elts = array( );

		foreach( $this->fieldsets as $fieldset )
		{
			$elts[] = '<fieldset>';
			$elts[] = '<legend>'.$fieldset->label.'</legend>';

			foreach( $fieldset->field_names as $field_name )
			{
				if( !empty($field->label) )
				{
					$elts[] = $field->label;
					$elts[] = $this->$field_name;
				}
				else
				{
					$elts[] = strval($this->$field_name);
				}
			}

			$elts[] = '</fieldset>';
		}

		return implode("\n", $elts);
	}

}

class Phorm_Field_Alpha extends Phorm_Field_Text
{
	public function validate($value)
	{
		$value = parent::validate($value);
		if( preg_match('/[0-9\s]*/iu', $value) )
		{
			throw new Phorm_ValidationError('field_invalid_alpha');
		}
	}

}

class Phorm_Field_AlphaNum extends Phorm_Field_Text
{
	public function validate($value)
	{
		$value = parent::validate($value);
		if( !preg_match('/^\S+$/iu', $value) )
		{
			throw new Phorm_ValidationError('field_invalid_alphanum');
		}
	}

}

class Phorm_Field_Checkbox extends Phorm_Field
{
	private $checked;

	public function __construct($label, array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, $validators, $attributes);
		parent::set_value('on');
		$this->checked = false;
	}

	public function set_value($value)
	{
		$this->checked = (boolean) $value;
	}

	public function get_value()
	{
		return $this->checked;
	}

	public function get_widget()
	{
		return new Phorm_Widget_Checkbox($this->checked);
	}

	public function validate($value)
	{
		return NULL;
	}

	public function import_value($value)
	{
		return $this->checked;
	}

	public function prepare_value($value)
	{
		return $value;
	}

}

class Phorm_Field_DateTime extends Phorm_Field_Text
{
	public function __construct($label, array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, 25, 100, $validators, $attributes);
	}

	public function validate($value)
	{
		parent::validate($value);

		if( !filter_var($value, FILTER_VALIDATE_REGEXP, array('options'=>array('regexp'=>'/^([0-9]{2})[\-|\/]([0-9]{2})[\-|\/]([0-9]{4})$/'))) )
		{
			throw new Phorm_ValidationError('field_invalid_datetime_format');
		}

		if( !strptime(strstr($value, '-', '/'), '%d/%m/%Y') )
		{
			throw new Phorm_ValidationError('field_invalid_datetime_format');
		}
	}

	public function import_value($value)
	{
		return strptime(strstr(parent::import_value($value), '-', '/'), '%d/%m/%Y');
	}

}

class Phorm_Field_Decimal extends Phorm_Field
{
	private $precision;

	public function __construct($label, $size, $precision, array $validators=array(), array $attributes=array())
	{
		$attributes['size'] = $size;
		parent::__construct($label, $validators, $attributes);
		$this->precision = $precision;
	}

	public function get_widget()
	{
		return new Phorm_Widget_Text();
	}

	public function validate($value)
	{
		if( !filter_var($value,FILTER_VALIDATE_FLOAT ) )
		{
			throw new Phorm_ValidationError('field_invalid_decimal');
		}
	}

	public function import_value($value)
	{
		return round((float) (html_entity_decode($value)), $this->precision);
	}

}

class Phorm_Field_DropDown extends Phorm_Field
{
	private $choices;

	public function __construct($label, array $choices, array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, $validators, $attributes);
		$this->choices = $choices;
	}

	public function get_widget()
	{
		return new Phorm_Widget_Select($this->choices);
	}

	public function validate($value)
	{
		if( !in_array($value, array_keys($this->choices)) )
		{
			throw new Phorm_ValidationError('field_invalid_dropdown');
		}
	}

	public function import_value($value)
	{
		return html_entity_decode((string) $value);
	}

}

class Phorm_Field_Email extends Phorm_Field_Text
{
	public function validate($value)
	{
		parent::validate($value);
		if( !filter_var($value, FILTER_VALIDATE_EMAIL) )
		{
			throw new Phorm_ValidationError('field_invalid_email');
		}
	}

}

class Phorm_Field_FileUpload extends Phorm_Field
{
	private $types;
	private $max_size;

	public function __construct($label, array $mime_types, $max_size, array $validators=array(), array $attributes=array())
	{
		$this->types = $mime_types;
		$this->max_size = $max_size;
		parent::__construct($label, $validators, $attributes);
	}

	protected function file_was_uploaded()
	{
		$file = $this->get_file_data();
		return !$file['error'];
	}

	protected function file_upload_error($errno)
	{
		global $phorms_tr;
		switch( $errno )
		{
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return 'field_file_toolarge';

			case UPLOAD_ERR_PARTIAL:
				return 'field_file_uploaderror';

			case UPLOAD_ERR_NO_FILE:
				return 'field_file_notsent';

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				return serialize(array('field_file_syserror', $errno));

			case UPLOAD_ERR_OK:
			default:
				return false;
		}
	}

	protected function get_widget()
	{
		return new Phorm_Widget_FileUpload($this->types);
	}

	protected function get_file_data()
	{
		$data = $_FILES[$this->get_attribute('name')];
		$data['error'] = $this->file_upload_error($data['error']);
		return $data;
	}

	protected function get_file()
	{
		return new Phorm_File($this->get_file_data());
	}

	public function import_value($value)
	{
		if( $this->file_was_uploaded() )
		{
			return $this->get_file();
		}
	}

	public function prepare_value($value)
	{
		if( $this->file_was_uploaded() )
		{
			return $this->get_file();
		}
		return false;
	}

	public function validate($value)
	{
		$file = $this->get_file_data();

		if( $file['error'] )
		{
			throw new Phorm_ValidationError($file['error']);
		}

		if( is_array($this->types) && !in_array($file['type'], $this->types) )
		{
			throw new Phorm_ValidationError(serialize(array('field_file_badtype', $file['type'])));
		}

		if( $file['size'] > $this->max_size )
		{
			throw new Phorm_ValidationError(serialize(array( 'field_file_sizelimit', number_format($this->max_size))) );
		}
	}

}

class Phorm_Field_Hidden extends Phorm_Field_Text
{
	public function __construct(array $validators=array(), array $attributes=array())
	{
		parent::__construct('', 25, 255, $validators, $attributes);
	}

	public function label()
	{
		return '';
	}

	public function help_text()
	{
		return '';
	}

	protected function get_widget()
	{
		return new Phorm_Widget_Hidden();
	}

}

class Phorm_Field_ImageUpload extends Phorm_Field_FileUpload
{
	public function __construct($label, $max_size, array $validators=array(), array $attributes=array(), array $allowed=array())
	{
		parent::__construct($label, array_merge($allowed, array( 'image/png', 'image/gif', 'image/jpg', 'image/jpeg' )), $max_size, $validators, $attributes);
	}

	protected function get_file()
	{
		return new Phorm_Type_Image($this->get_file_data());
	}

}

class Phorm_Field_Integer extends Phorm_Field
{
	private $max_digits;

	public function __construct($label, $size, $max_digits, array $validators=array(), array $attributes=array())
	{
		$this->max_digits = $max_digits;
		$attributes['maxlength'] = $max_digits;
		$attributes['size'] = $size;
		parent::__construct($label, $validators, $attributes);
	}

	public function get_widget()
	{
		return new Phorm_Widget_Text();
	}

	public function validate($value)
	{
		if( !filter_var($value,FILTER_VALIDATE_INT) )
		{
			throw new Phorm_ValidationError('field_invalid_integer');
		}

		if( strlen((string) $value) > $this->max_digits )
		{
			throw new Phorm_ValidationError(serialize(array('field_invalid_integer_sizelimit', $this->max_digits)));
		}
	}

	public function import_value($value)
	{
		return (int) (html_entity_decode((string) $value));
	}

}

class Phorm_Field_MultipleChoice extends Phorm_Field
{
	public $multi_field = TRUE;
	private $choices;
	private $widget;

	public function __construct($label, array $choices, $widget='Phorm_Widget_SelectMultiple', array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, $validators, $attributes);
		$this->choices = $choices;
		$this->widget = $widget;
	}

	public function get_widget()
	{
		switch( $this->widget )
		{
			case 'Phorm_Widget_SelectMultiple':
				return new Phorm_Widget_SelectMultiple($this->choices);
			case 'Phorm_Widget_Radio':
			case 'Phorm_Widget_Checkbox':
				return new Phorm_Widget_OptionGroup($this->choices, $this->widget);
			default:
				throw new Exception('Invalid widget: '.(string) $this->widget);
		}
	}

	public function validate($value)
	{

		if( !is_array($value) )
		{
			throw new Phorm_ValidationError('field_invalid_multiplechoice_badformat');
		}

		foreach( $value as $v )
		{
			if( !in_array($v, array_keys($this->choices)) )
			{
				throw new Phorm_ValidationError('field_invalid_multiplechoice_badformat');
			}
		}
	}

	public function import_value($value)
	{
		if( is_array($value) )
		{
			foreach( $value as $key => &$val )
			{
				$val = html_entity_decode($val);
			}
		}
		return $value;
	}

	public function prepare_value($value)
	{
		if( is_array($value) && get_magic_quotes_gpc() )
		{
			foreach( $value as $key => &$val )
			{
				$val = stripslashes($val);
			}
		}
		return $value;
	}

}

class Phorm_Field_Password extends Phorm_Field_Text
{
	private $hash_function;

	public function __construct($label, $size, $max_length, $hash_function, array $validators=array(), array $attributes=array())
	{
		$this->hash_function = $hash_function;
		parent::__construct($label, $size, $max_length, $validators, $attributes);
	}

	public function get_widget()
	{
		return new Phorm_Widget_Password();
	}

	public function import_value($value)
	{
		return call_user_func($this->hash_function, $value);
	}

}

class Phorm_Field_Regex extends Phorm_Field_Text
{
	private $regex;
	private $message;
	private $matches;

	public function __construct($label, $regex, $error_msg, array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, 25, 100, $validators, $attributes);
		$this->regex = $regex;
		$this->message = $error_msg;
	}

	public function validate($value)
	{
		parent::validate($value);
		if( !filter_var($value, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => $this->regex) ) ) )
		{
			throw new Phorm_ValidationError($this->message);
		}
	}

	public function import_value($value)
	{
		return $this->matches;
	}

}

class Phorm_Field_Scan extends Phorm_Field_Text
{
	private $format;
	private $message;
	private $matched;

	public function __construct($label, $format, $error_msg, array $validators=array(), array $attributes=array())
	{
		parent::__construct($label, 25, 100, $validators, $attributes);
		$this->format = $format;
		$this->message = $error_msg;
	}

	public function validate($value)
	{
		parent::validate($value);
		$this->matched = sscanf($value, $this->format);
		if( empty($this->matched) )
		{
			throw new Phorm_ValidationError($this->message);
		}
	}

	public function import_value($value)
	{
		return $this->matched;
	}

}

class Phorm_Field_Text extends Phorm_Field
{
	private $max_length;

	public function __construct($label, $size, $max_length, array $validators=array(), array $attributes=array())
	{
		$this->max_length = $max_length;
		$attributes['maxlength'] = $max_length;
		$attributes['size'] = $size;
		parent::__construct($label, $validators, $attributes);
	}

	protected function get_widget()
	{
		return new Phorm_Widget_Text();
	}

	public function validate($value)
	{
		if( strlen($value) > $this->max_length )
		{
			throw new Phorm_ValidationError(serialize(array( 'field_invalid_text_sizelimit', $this->max_length)));
		}
		return $value;
	}

	public function import_value($value)
	{
		return html_entity_decode((string) $value);
	}

}

class Phorm_Field_Textarea extends Phorm_Field
{
	public function __construct($label, $rows, $cols, array $validators=array(), array $attributes=array())
	{
		$attributes['cols'] = $cols;
		$attributes['rows'] = $rows;
		parent::__construct($label, $validators, $attributes);
	}

	protected function get_widget()
	{
		return new Phorm_Widget_Textarea();
	}

	public function validate($value)
	{
		return TRUE;
	}

	public function import_value($value)
	{
		return html_entity_decode((string) $value);
	}

}

class Phorm_Field_URL extends Phorm_Field_Text
{
	public function prepare_value($value)
	{
		if( !empty($value) && !filter_var($value,FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED) )
		{
			return 'http://'.$value;
		}

		return filter_var($value, FILTER_SANITIZE_URL);
	}

	public function validate($value)
	{
		$value = parent::validate($value);
		if( !filter_var($value,FILTER_VALIDATE_URL,FILTER_FLAG_SCHEME_REQUIRED) )
		{
			throw new Phorm_ValidationError('field_invalid_url');
		}
	}

}

class Phorm_Type_File
{
	public $name;
	public $type;
	public $tmp_name;
	public $error;
	public $bytes;

	public function __construct(array $file_data)
	{
		$this->name = $file_data['name'];
		$this->type = $file_data['type'];
		$this->tmp_name = $file_data['tmp_name'];
		$this->error = $file_data['error'];
		$this->bytes = $file_data['size'];
	}

	public function move_to($path)
	{
		$new_name = sprintf('%s/%s', $path, $this->name);
		move_uploaded_file($this->tmp_name, $new_name);
		return $new_name;
	}

}

class Phorm_Type_Image extends Phorm_Type_File
{

	public $width;
	public $height;
	public $type;

	public function __construct($file_data)
	{
		parent::__construct($file_data);
		list($this->width, $this->height, $this->type) = getimagesize($this->tmp_name);
	}

}

class Phorm_Widget_Cancel extends Phorm_Widget
{
	private $url;

	public function __construct($url)
	{
		$this->url = $url;
	}

	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'button';
		$attributes['onclick'] = 'window.location.href=\''.str_replace("'", "\'", $this->url).'\'';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Checkbox extends Phorm_Widget
{
	private $checked = FALSE;

	public function __construct($checked=FALSE)
	{
		$this->checked = $checked;
	}

	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'checkbox';
		if( $this->checked )
		{
			$attributes['checked'] = 'checked';
		}
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_FileUpload extends Phorm_Widget
{
	private $types;

	public function __construct(array $valid_mime_types)
	{
		$this->types = $valid_mime_types;
	}

	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'file';
		$attributes['accept'] = implode(',', $this->types);
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Hidden extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'hidden';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_OptionGroup extends Phorm_Widget
{
	private $options;
	private $widget;

	public function __construct(array $options, $widget='Phorm_Widget_Checkbox')
	{
		$this->options = $options;
		$this->widget = $widget;
	}

	public function html($value, array $attributes=array())
	{
		if( is_null($value) )
			$value = array();

		foreach( $attributes as $key => $val )
		{
			$attributes[htmlentities((string) $key)] = htmlentities((string) $val);
		}

		return $this->serialize($value, $attributes);
	}

	protected function serialize($value, array $attributes=array())
	{
		$html = "";
		foreach( $this->options as $actual => $display )
		{
			$option = new $this->widget(in_array($actual, $value));
			$html .= sprintf("<label>%s %s</label>\n", $option->html($actual, $attributes), htmlentities($display));
		}

		return $html;
	}

}

class Phorm_Widget_Password extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'password';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Radio extends Phorm_Widget
{
	private $checked;

	public function __construct($checked=false)
	{
		$this->checked = $checked;
	}

	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'radio';
		if( $this->checked )
		{
			$attributes['checked'] = 'checked';
		}
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Reset extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'reset';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Select extends Phorm_Widget
{
	private $choices;

	public function __construct(array $choices)
	{
		$this->choices = $choices;
	}

	protected function serialize($value, array $attributes=array())
	{
		$options = array();
		foreach( $this->choices as $actual => $display )
		{
			$option_attributes = array( 'value' => $this->clean_string($actual) );
			if( $actual == $value )
			{
				$option_attributes['selected'] = 'selected';
			}
			$options[] = sprintf("<option %s>%s</option>\n", $this->serialize_attributes($option_attributes), $this->clean_string($display));
		}

		return sprintf('<select %s>%s</select>', $this->serialize_attributes($attributes), implode($options));
	}

}

class Phorm_Widget_SelectMultiple extends Phorm_Widget
{
	private $choices;

	public function __construct(array $choices)
	{
		$this->choices = $choices;
	}

	public function html($value, array $attributes=array())
	{
		if( is_null($value) )
			$value = array();

		foreach( $attributes as $key => $val )
		{
			$attributes[htmlentities((string) $key)] = htmlentities((string) $val);
		}

		return $this->serialize($value, $attributes);
	}

	protected function serialize($value, array $attributes=array())
	{
		if( is_null($value) )
		{
			$value = array();
		}

		if( !is_array($value) )
		{
			$value = array( $value );
		}

		$options = array();
		foreach( $this->choices as $actual => $display )
		{
			$option_attributes = array( 'value' => $this->clean_string($actual) );
			if( in_array($actual, $value) )
			{
				$option_attributes['selected'] = 'selected';
			}
			$options[] = sprintf("<option %s>%s</option>\n", $this->serialize_attributes($option_attributes), $this->clean_string($display));
		}

		return sprintf('<select multiple="multiple" %s>%s</select>', $this->serialize_attributes($attributes), implode($options));
	}

}

class Phorm_Widget_Submit extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'submit';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Text extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		$attributes['type'] = 'text';
		return parent::serialize($value, $attributes);
	}

}

class Phorm_Widget_Textarea extends Phorm_Widget
{
	protected function serialize($value, array $attributes=array())
	{
		return sprintf('<textarea %s>%s</textarea>', $this->serialize_attributes($attributes), $value);
	}

}
