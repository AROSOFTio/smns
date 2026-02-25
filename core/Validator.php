<?php
/**
 * Input Validation Class
 * Provides validation methods for form inputs
 */
class Validator {
    private $errors = [];
    private $data = [];
    
    public function __construct($data = []) {
        $this->data = $data;
    }
    
    /**
     * Validate required field
     */
    public function required($field, $message = null) {
        if (empty($this->data[$field])) {
            $this->errors[$field] = $message ?? "$field is required";
        }
        return $this;
    }
    
    /**
     * Validate email
     */
    public function email($field, $message = null) {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $message ?? "Invalid email format";
        }
        return $this;
    }
    
    /**
     * Validate minimum length
     */
    public function min($field, $length, $message = null) {
        if (!empty($this->data[$field]) && strlen($this->data[$field]) < $length) {
            $this->errors[$field] = $message ?? "$field must be at least $length characters";
        }
        return $this;
    }

    /**
     * Backward-compatible alias for minimum length validation.
     */
    public function minLength($field, $length, $message = null) {
        return $this->min($field, $length, $message);
    }
    
    /**
     * Validate maximum length
     */
    public function max($field, $length, $message = null) {
        if (!empty($this->data[$field]) && strlen($this->data[$field]) > $length) {
            $this->errors[$field] = $message ?? "$field must not exceed $length characters";
        }
        return $this;
    }

    /**
     * Backward-compatible alias for maximum length validation.
     */
    public function maxLength($field, $length, $message = null) {
        return $this->max($field, $length, $message);
    }
    
    /**
     * Validate exact length
     */
    public function length($field, $length, $message = null) {
        if (!empty($this->data[$field]) && strlen($this->data[$field]) !== $length) {
            $this->errors[$field] = $message ?? "$field must be exactly $length characters";
        }
        return $this;
    }
    
    /**
     * Validate numeric
     */
    public function numeric($field, $message = null) {
        if (!empty($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->errors[$field] = $message ?? "$field must be numeric";
        }
        return $this;
    }
    
    /**
     * Validate integer
     */
    public function integer($field, $message = null) {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_INT)) {
            $this->errors[$field] = $message ?? "$field must be an integer";
        }
        return $this;
    }
    
    /**
     * Validate decimal
     */
    public function decimal($field, $message = null) {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_FLOAT)) {
            $this->errors[$field] = $message ?? "$field must be a decimal number";
        }
        return $this;
    }
    
    /**
     * Validate date
     */
    public function date($field, $format = 'Y-m-d', $message = null) {
        if (!empty($this->data[$field])) {
            $d = DateTime::createFromFormat($format, $this->data[$field]);
            if (!$d || $d->format($format) !== $this->data[$field]) {
                $this->errors[$field] = $message ?? "Invalid date format for $field";
            }
        }
        return $this;
    }
    
    /**
     * Validate matches another field
     */
    public function matches($field, $matchField, $message = null) {
        if (!empty($this->data[$field]) && $this->data[$field] !== $this->data[$matchField]) {
            $this->errors[$field] = $message ?? "$field does not match $matchField";
        }
        return $this;
    }
    
    /**
     * Validate unique in database
     */
    public function unique($field, $table, $column, $exceptId = null, $message = null) {
        if (!empty($this->data[$field])) {
            $db = new Database();
            $conn = $db->getConnection();
            
            $sql = "SELECT COUNT(*) as count FROM $table WHERE $column = :value";
            if ($exceptId) {
                $sql .= " AND id != :except_id";
            }
            
            $stmt = $conn->prepare($sql);
            $params = ['value' => $this->data[$field]];
            if ($exceptId) {
                $params['except_id'] = $exceptId;
            }
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $this->errors[$field] = $message ?? "$field already exists";
            }
        }
        return $this;
    }
    
    /**
     * Validate pattern (regex)
     */
    public function pattern($field, $pattern, $message = null) {
        if (!empty($this->data[$field]) && !preg_match($pattern, $this->data[$field])) {
            $this->errors[$field] = $message ?? "$field has invalid format";
        }
        return $this;
    }
    
    /**
     * Validate in array
     */
    public function in($field, $values, $message = null) {
        if (!empty($this->data[$field]) && !in_array($this->data[$field], $values)) {
            $this->errors[$field] = $message ?? "Invalid value for $field";
        }
        return $this;
    }
    
    /**
     * Check if validation passed
     */
    public function passed() {
        return empty($this->errors);
    }
    
    /**
     * Get validation errors
     */
    public function errors() {
        return $this->errors;
    }
    
    /**
     * Get first error
     */
    public function firstError() {
        return reset($this->errors) ?: null;
    }

    /**
     * Add an explicit validation error for a field.
     */
    public function addError($field, $message) {
        $this->errors[$field] = $message;
        return $this;
    }
    
    /**
     * Get error for specific field
     */
    public function error($field) {
        return $this->errors[$field] ?? null;
    }
}
