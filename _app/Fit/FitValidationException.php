<?php
declare(strict_types=1);

final class FitValidationException extends RuntimeException {
  public function __construct(public readonly string $errorCode, string $message) {
    parent::__construct($message);
  }

  public function toArray(): array {
    return [
      'code' => $this->errorCode,
      'message' => $this->getMessage(),
    ];
  }
}
