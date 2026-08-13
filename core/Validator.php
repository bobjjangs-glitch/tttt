<?php
declare(strict_types=1);

final class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function require(string $field, string $label): self
    {
        if (trim((string)($this->data[$field] ?? '')) === '') {
            $this->errors[$field] = "{$label}을(를) 입력해주세요.";
        }
        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->data[$field] ?? '';
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = '올바른 이메일 형식이 아닙니다.';
        }
        return $this;
    }

    public function minLength(string $field, int $len, string $label): self
    {
        $v = $this->data[$field] ?? '';
        if ($v !== '' && mb_strlen($v) < $len) {
            $this->errors[$field] = "{$label}은(는) 최소 {$len}자 이상이어야 합니다.";
        }
        return $this;
    }

    public function phone(string $field): self
    {
        $v = $this->data[$field] ?? '';
        if ($v !== '' && !preg_match('/^01[0-9]-?\d{3,4}-?\d{4}$/', $v)) {
            $this->errors[$field] = '올바른 휴대폰 번호 형식이 아닙니다.';
        }
        return $this;
    }

    public function passwordStrength(string $field): self
    {
        $v = $this->data[$field] ?? '';
        if ($v !== '' && !preg_match('/^(?=.*[a-zA-Z])(?=.*\d).{8,}$/', $v)) {
            $this->errors[$field] = '비밀번호는 8자 이상, 영문과 숫자를 포함해야 합니다.';
        }
        return $this;
    }

    public function bizNumber(string $field): self
    {
        $v = preg_replace('/[^0-9]/', '', $this->data[$field] ?? '');
        if ($v === '') return $this;

        if (strlen($v) !== 10) {
            $this->errors[$field] = '사업자등록번호는 숫자 10자리여야 합니다.';
            return $this;
        }

        $weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$v[$i] * $weights[$i];
        }
        $sum += intdiv(((int)$v[8] * 5), 10);
        $checkDigit = (10 - ($sum % 10)) % 10;

        if ($checkDigit !== (int)$v[9]) {
            $this->errors[$field] = '유효하지 않은 사업자등록번호입니다.';
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
