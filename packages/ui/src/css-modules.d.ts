// UNIT: CSS-MODULES.D
// FILE: css-modules.d.ts
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

declare module '*.module.css' {
  const classes: Record<string, string>;
  export default classes;
}
