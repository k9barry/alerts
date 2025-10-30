<?php

if (!class_exists('MockResponse')) {
    class MockResponse {
        private int $status;
        private string $body;
        public function __construct(int $status, string $body = '') { $this->status = $status; $this->body = $body; }
        public function getStatusCode() { return $this->status; }
        public function getBody() { return $this; }
        public function getContents() { return $this->body; }
        public function __toString() { return $this->body; }
    }
}
