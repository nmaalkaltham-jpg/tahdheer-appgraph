'use client';
// UNIT: USERS-PANEL
// FILE: users-panel.tsx
// STYLE: none
// PARENT: PAGE-SETTINGS
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: DELETE /admin/users
// MAP: docs/PROJECT_MAP.md

/**
 * المستخدمون والأدوار.
 *
 * كانت شاشة الإعدادات تُدرج هذا القسم تحت «ما لم يُبْنَ» بسببٍ مكتوب: «الحساب
 * يُنشأ من الخادم عبر AdminUserSeeder». وقد بُني الآن — الإقرار حُذف لأنّه صار
 * كذبًا، لا لأنّه صار محرجًا.
 *
 * ─── الواجهة تُعطّل ما سيرفضه الخادم، ولا تدّعي أنّها هي من يمنع ───
 *
 * الأقفال الثلاثة (لا تحذف نفسك · لا تغيّر دورك · لا تُفني آخر مالك) مفروضة في
 * `AdminUserController` ومُختبَرة هناك. وما هنا **صدى** لها: زرٌّ معطَّل بالسبب
 * نفسه، ليعرف المشغّل لماذا قبل أن يضغط لا بعده. ولو تغيّرت قاعدة في الخادم
 * ولم تتغيّر هنا، فالخادم هو من يفوز — تظهر رسالته كما هي في مكانها.
 *
 * ─── وكلمة المرور ───
 *
 * تُرسَل ولا تُعرض ولا تُخزَّن في الحالة بعد النجاح. ولا «توليد تلقائي» يُعرض
 * على الشاشة: قيمةٌ سرّية تُرسم في DOM تُلتقط في لقطة شاشة وتبقى في سجلّ
 * المتصفّح — والمشغّل يعرف كيف يولّدها بأداة تثق بها مؤسّسته.
 *
 * ─── الصفحة كلّها خلف صلاحية واحدة ───
 *
 * هذه حالة **صفحة** لا حالة لوح: البادئة كلّها — القراءة والإنشاء والتعديل
 * وتصفير كلمة المرور والحذف — خلف `users.manage`
 * (`routes/api-admin.php:350-351` — `Route::prefix('users')`
 * `->middleware(AdminAuthorize::class.':users.manage')`). فلا جزء من الشاشة
 * يبقى مقروءًا لمن لا يملكها، ولا معنى لتعطيلٍ جزئيّ.
 */

import { useCallback, useState, type FormEvent, type ReactNode } from 'react';
import { useSession } from '../../../../components/admin-session';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../../components/resource';
import { api, firstError } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';

interface AdminUserRow {
  id: number;
  name: string;
  email: string;
  role: string;
  roleLabel: string;
  lastLoginAt: string | null;
  createdAt: string | null;
}

interface RoleOption {
  value: string;
  label: string;
  permissions: { key: string; label: string }[];
}

interface UsersPayload {
  data: AdminUserRow[];
  roles: RoleOption[];
  meta: { ownerCount: number; currentUserId: number | null; minPasswordLength: number };
}

/** سبب تعطيل إجراء على صفّ، أو `null` إن كان مسموحًا. */
function blockedReason(
  row: AdminUserRow,
  meta: UsersPayload['meta'],
  action: 'delete' | 'role',
): string | null {
  if (row.id === meta.currentUserId) {
    return action === 'delete'
      ? 'لا يمكنك حذف حسابك وأنت تستعمله — ليحذفه مالك آخر.'
      : 'لا يمكنك تغيير دور حسابك بنفسك: خفضُه يسحب منك صلاحية إدارة المستخدمين فورًا فلا تستطيع التراجع.';
  }
  if (row.role === 'owner' && meta.ownerCount <= 1) {
    return 'هذا آخر حساب بدور «المالك»، وهو وحده يملك إدارة المستخدمين. عيّن مالكًا آخر أوّلًا.';
  }
  return null;
}

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * `nav.ts` يُخفي المدخل عمّن لا يملك `users.manage`، والرابط المباشر يصل. من
 * فتحه بلا الصلاحية كان يقرأ رسالةً عامّة وزرّ «إعادة المحاولة» لا يمنح
 * صلاحية مهما كُرّر. فتُسمّى الصلاحية باسمها ويُنزع الزرّ.
 *
 * الحارس: `routes/api-admin.php:351` — ‎->middleware(AdminAuthorize::class.':users.manage')‎.
 */
function UsersState({ state, reload, children }: {
  state: ResourceState<UsersPayload>;
  reload: () => void;
  children: (payload: UsersPayload) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">users.manage</bdi> لقراءة حسابات اللوحة وإدارتها. قائمةُ من
          يملك الوصول وبأيّ دور محروسةٌ بالصلاحية نفسها التي تغيّرها — لا قراءة تحتها.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

export function AdminUsersPanel() {
  const { can } = useSession();
  /*
   * صدى الحارس لا بديلٌ عنه: `AdminAuthorize` يردّ ٤٠٣ على كلّ فعل هنا. وما
   * تفعله هذه الراية أن تمنع عرض زرٍّ يَعِد بفعلٍ سيُرفض — لا أن تحمي المسار.
   */
  const manage = can('users.manage');
  const { state, reload } = useResource<UsersPayload>('/admin/users');
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | 'create' | null>(null);
  /** الصفّ الذي فُتح له حقل كلمة المرور — واحد في كل مرة. */
  const [resetting, setResetting] = useState<number | null>(null);

  const run = useCallback(
    async (key: number | 'create', task: () => Promise<{ ok: boolean; message?: string }>, done: string) => {
      setBusy(key);
      setError(null);
      setNote(null);
      const result = await task();
      setBusy(null);
      if (!result.ok) {
        setError(result.message ?? 'تعذّر تنفيذ العملية.');
        return false;
      }
      setNote(done);
      reload();
      return true;
    },
    [reload],
  );

  return (
    <section>

      <p className="page-note">
        الأدوار وصلاحياتها يفرضها الخادم على كل مسار. ما تراه هنا هو الكتالوج نفسه الذي يحكم الطلبات — لا نسخة
        منه.
      </p>

      {error !== null ? (
        <p className="state-line" role="alert">
          {error}
        </p>
      ) : null}
      {note !== null ? (
        <p className="state-line" role="status">
          {note}
        </p>
      ) : null}

      <UsersState state={state} reload={reload}>
        {(payload) => (
          <>
            {manage ? (
              <CreateForm
                roles={payload.roles}
                minPassword={payload.meta.minPasswordLength}
                busy={busy === 'create'}
                onSubmit={(body) =>
                  run('create', () => api.post('/admin/users', body).then(toOutcome), 'أُنشئ الحساب.')
                }
              />
            ) : (
              <p className="absent">
                إنشاء الحسابات وتغيير الأدوار وتصفير كلمات المرور والحذف كلّها خلف{' '}
                <bdi dir="ltr">users.manage</bdi> — لا نموذج يُعرض بفعلٍ يردّه الخادم.
              </p>
            )}

            <div className="table-scroll" tabIndex={0} role="region" aria-label="حسابات لوحة التشغيل">
              <table className="data-table">
                <caption className="sr-only">حسابات لوحة التشغيل</caption>
                <thead>
                  <tr>
                    <th scope="col">الاسم</th>
                    <th scope="col">البريد</th>
                    <th scope="col">الدور</th>
                    <th scope="col">آخر دخول</th>
                    <th scope="col">إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows colSpan={5} message="لا حسابات." />
                  ) : (
                    payload.data.map((row) => {
                      /* الصلاحية أوّلًا: من لا يملكها لا يُقال له «آخر مالك» بل يُقال له إنّه لا يملكها. */
                      const roleBlock = manage ? blockedReason(row, payload.meta, 'role') : NO_MANAGE;
                      const deleteBlock = manage ? blockedReason(row, payload.meta, 'delete') : NO_MANAGE;
                      const rowBusy = busy === row.id;

                      return (
                        <tr key={row.id}>
                          <td>
                            {row.name}
                            {row.id === payload.meta.currentUserId ? (
                              <>
                                {' '}
                                <span className="chip">أنت</span>
                              </>
                            ) : null}
                          </td>
                          <td>
                            <bdi dir="ltr">{row.email}</bdi>
                          </td>
                          <td>
                            <label className="sr-only" htmlFor={`role-${row.id}`}>
                              دور {row.name}
                            </label>
                            <select
                              id={`role-${row.id}`}
                              value={row.role}
                              disabled={roleBlock !== null || rowBusy}
                              title={roleBlock ?? undefined}
                              onChange={(event) =>
                                void run(
                                  row.id,
                                  () =>
                                    api
                                      .patch(`/admin/users/${row.id}`, { role: event.target.value })
                                      .then(toOutcome),
                                  `تغيّر دور ${row.name}.`,
                                )
                              }
                            >
                              {payload.roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                  {role.label}
                                </option>
                              ))}
                            </select>
                          </td>
                          <td>{row.lastLoginAt === null ? 'لم يدخل بعد' : dateTime(row.lastLoginAt)}</td>
                          <td>
                            <button
                              type="button"
                              className="btn btn-ghost"
                              disabled={!manage || rowBusy}
                              title={manage ? undefined : NO_MANAGE}
                              onClick={() => setResetting(resetting === row.id ? null : row.id)}
                            >
                              كلمة مرور جديدة
                            </button>{' '}
                            <button
                              type="button"
                              className="btn btn-ghost"
                              disabled={deleteBlock !== null || rowBusy}
                              title={deleteBlock ?? undefined}
                              onClick={() =>
                                void run(
                                  row.id,
                                  () => api.delete(`/admin/users/${row.id}`).then(toOutcome),
                                  `حُذف حساب ${row.name}.`,
                                )
                              }
                            >
                              حذف
                            </button>
                            {resetting === row.id ? (
                              <PasswordForm
                                minPassword={payload.meta.minPasswordLength}
                                busy={rowBusy}
                                onSubmit={async (password) => {
                                  const ok = await run(
                                    row.id,
                                    () =>
                                      api
                                        .post(`/admin/users/${row.id}/password`, { password })
                                        .then(toOutcome),
                                    `عُيّنت كلمة مرور جديدة لـ${row.name}.`,
                                  );
                                  if (ok) setResetting(null);
                                }}
                              />
                            ) : null}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            <RoleCatalogue roles={payload.roles} />
          </>
        )}
      </UsersState>
    </section>
  );
}

/** سبب واحد يُكتب مرّة: الفعل محجوب بالصلاحية لا بقاعدة الصفّ. */
const NO_MANAGE = 'لا تملك صلاحية users.manage — الخادم يردّ هذا الفعل بـ403.';

/** توحيد شكل نتيجة النداء — الرسالة العربية تأتي من الخادم كما هي. */
function toOutcome(result: { ok: boolean } & Record<string, unknown>): { ok: boolean; message?: string } {
  return result.ok
    ? { ok: true }
    : { ok: false, message: firstError(result as Parameters<typeof firstError>[0]) };
}

function CreateForm({
  roles,
  minPassword,
  busy,
  onSubmit,
}: {
  roles: RoleOption[];
  minPassword: number;
  busy: boolean;
  onSubmit: (body: { name: string; email: string; password: string; role: string }) => Promise<boolean>;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState('read_only');

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const ok = await onSubmit({ name: name.trim(), email: email.trim(), password, role });
    if (!ok) return;
    // السرّ لا يبقى في الحالة بعد نجاح الإرسال.
    setName('');
    setEmail('');
    setPassword('');
  };

  return (
    <form className="filters" onSubmit={(event) => void submit(event)}>
      <label className="field field-inline" htmlFor="new-name">
        <span>الاسم</span>
        <input id="new-name" type="text" value={name} onChange={(e) => setName(e.target.value)} required />
      </label>
      <label className="field field-inline" htmlFor="new-email">
        <span>البريد</span>
        <input
          id="new-email"
          type="email"
          dir="ltr"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
      </label>
      <label className="field field-inline" htmlFor="new-password">
        <span>كلمة المرور</span>
        <input
          id="new-password"
          type="password"
          dir="ltr"
          value={password}
          minLength={minPassword}
          autoComplete="new-password"
          onChange={(e) => setPassword(e.target.value)}
          required
        />
      </label>
      <label className="field field-inline" htmlFor="new-role">
        <span>الدور</span>
        <select id="new-role" value={role} onChange={(e) => setRole(e.target.value)}>
          {roles.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
            </option>
          ))}
        </select>
      </label>
      <button type="submit" className="btn btn-primary" disabled={busy}>
        {busy ? 'جارٍ الإنشاء…' : 'إنشاء حساب'}
      </button>
      <span className="list-toolbar-end">
        أقلّ طول لكلمة المرور <bdi dir="ltr">{minPassword}</bdi> محرفًا — يفرضه الخادم.
      </span>
    </form>
  );
}

function PasswordForm({
  minPassword,
  busy,
  onSubmit,
}: {
  minPassword: number;
  busy: boolean;
  onSubmit: (password: string) => Promise<void>;
}) {
  const [value, setValue] = useState('');

  return (
    <form
      className="filters"
      onSubmit={(event) => {
        event.preventDefault();
        void onSubmit(value).then(() => setValue(''));
      }}
    >
      <label className="field field-inline">
        <span className="sr-only">كلمة المرور الجديدة</span>
        <input
          type="password"
          dir="ltr"
          value={value}
          minLength={minPassword}
          autoComplete="new-password"
          placeholder={`${minPassword} محارف على الأقل`}
          onChange={(event) => setValue(event.target.value)}
          required
        />
      </label>
      <button type="submit" className="btn btn-primary" disabled={busy}>
        تعيين
      </button>
    </form>
  );
}

/**
 * جدول الأدوار وصلاحياتها — يُقرأ من ردّ الخادم لا من ثابت في الواجهة.
 *
 * من يمنح دورًا يجب أن يرى ما يمنحه. وقائمةُ اختيارٍ بأسماء أدوار وحدها تجعل
 * منح «مدير النظام» و«قراءة فقط» متساويَين في المظهر — وهما ليسا كذلك.
 */
function RoleCatalogue({ roles }: { roles: RoleOption[] }) {
  return (
    <section className="panel">
      <h2>ما يمنحه كل دور</h2>
      <div className="table-scroll" tabIndex={0} role="region" aria-label="ما يمنحه كل دور من صلاحيات">
        <table className="data-table">
          <caption className="sr-only">الأدوار والصلاحيات التي يمنحها كلّ دور</caption>
          <thead>
            <tr>
              <th scope="col">الدور</th>
              <th scope="col">الصلاحيات</th>
            </tr>
          </thead>
          <tbody>
            {roles.map((role) => (
              <tr key={role.value}>
                <th scope="row">
                  {role.label} (<bdi dir="ltr">{role.value}</bdi>)
                </th>
                <td>{role.permissions.map((p) => p.label).join(' · ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
