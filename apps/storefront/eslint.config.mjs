// Flat ESLint config (Next 16 dropped `next lint` — eslint runs directly).
import coreWebVitals from 'eslint-config-next/core-web-vitals';
import typescript from 'eslint-config-next/typescript';

export default [...coreWebVitals, ...typescript, { ignores: ['.next/**'] }];
