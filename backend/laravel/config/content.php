<?php

/*
 * قراءة محتوى الملفّات (ContentIntelligence).
 *
 * `pdftotext` من poppler-utils، مثبَّتٌ في صورة الحاوية (Dockerfile). غيابُه
 * لا يكسر شيئًا: PdfTextExtractor يعود إلى قراءة البيانات الوصفيّة وحدها.
 */
return [
    'pdftotext' => env('PDFTOTEXT_BIN', '/usr/bin/pdftotext'),
    'pdftotext_timeout' => (int) env('PDFTOTEXT_TIMEOUT', 30),

    /* Tesseract (tesseract-ocr + ara/eng) — صورٌ وصفحاتُ PDF ممسوحة. */
    'tesseract' => env('TESSERACT_BIN', '/usr/bin/tesseract'),
    'ocr_timeout' => (int) env('OCR_TIMEOUT', 60),
];
