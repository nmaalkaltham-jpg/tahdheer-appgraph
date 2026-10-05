// UNIT: AI-INDEX
// FILE: index.ts
// STYLE: none
// PARENT: STUDIO-CLIENT, _SECTION-CONTENT-PRODUCTS
// USES: AI-ASSIST-PANEL, PRODUCT-COPILOT, PRODUCT-FACTORY-PANEL
// API: none
// API-VIA: PRODUCT-COPILOT
// MAP: docs/PROJECT_MAP.md

/**
 * لوح المساعدة الكتابية المشترك — نقطة التصدير الوحيدة.
 *
 * تركّبه الاستوديوهات حيث تُحرَّر النصوص. حارسا `isValidSuggestion` و
 * `rejectedLockedKeys` جزء من العقد لا زينة: أي مسار اعتماد يُبنى لاحقًا
 * يمرّ بهما قبل أن يكتب في أي حقل.
 */

export { default as AiAssistPanel, isValidSuggestion, rejectedLockedKeys } from './ai-assist-panel';
export type { AiAssistPanelProps, AiFact, AiSuggestion } from './ai-assist-panel';
export { ProductFactoryPanel } from './product-factory-panel';
export { ProductCopilot } from './product-copilot';
