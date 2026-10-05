// Flat ESLint config (Next 16 dropped `next lint` — eslint runs directly).
//
// نسخة مطابقة لإعداد المتجر عمدًا: تطبيقان في مستودع واحد بقاعدتين مختلفتين
// يعني أنّ ما يُرفض في أحدهما يمرّ في الآخر — والمراجعة تفقد معناها.
//
// اللوحة كانت **بلا سكربت lint أصلًا**، فوظيفة ESLint في CI تمرّ عليها
// بـ`--if-present` أي لا تفحصها إطلاقًا. عشرات الآلاف من الأسطر بلا فحص.
import coreWebVitals from 'eslint-config-next/core-web-vitals';
import typescript from 'eslint-config-next/typescript';

export default [...coreWebVitals, ...typescript, { ignores: ['.next/**'] }];
