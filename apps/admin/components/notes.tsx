'use client';
// UNIT: NOTES
// FILE: notes.tsx
// STYLE: none
// PARENT: PAGE-CUSTOMERS-ID, PAGE-ORDERS-ID
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: DELETE /admin/notes
// MAP: docs/PROJECT_MAP.md

/**
 * ملاحظات المشغّلين على كيان (طلب أو عميل).
 *
 * ─── ما تفعله هذه الميزة ولا يفعله سجلّ التدقيق ───
 *
 * التدقيق يسجّل ما فعله **النظام**: مبلغٌ رُدّ، صلاحيةٌ سُحبت، دورٌ تغيّر.
 * والملاحظة تسجّل ما فهمه **الإنسان**: «طلب الاسترداد هاتفيًّا قبل التنزيل»،
 * «أُرسل الملفّ يدويًّا بعد عطل الرابط». وبلا مكان لهذا، يُكتب في واتساب أو
 * لا يُكتب — فيضيع مع أوّل تسليم مناوبة.
 *
 * ─── القدرة تأتي من الخادم لا تُستنتج هنا ───
 *
 * كل صفّ يصل ومعه `canEdit` و`canDelete`، والحمولة ومعها `canWrite`. فالواجهة
 * لا تعيد بناء قاعدة «الكاتب وحده يعدّل، والمالك يحذف قول غيره» — قاعدةٌ
 * مكرَّرة في مكانين تتفارق عند أوّل تعديل، والنسخة الصامتة هنا هي التي ستكذب.
 */

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { ResourceView, useResource } from './resource';
import { api, firstError } from '../lib/admin-api';
import { dateTime } from '../lib/format';

interface NoteRow {
  id: number;
  body: string;
  authorEmail: string;
  authorId: number | null;
  createdAt: string | null;
  editedAt: string | null;
  edited: boolean;
  canEdit: boolean;
  canDelete: boolean;
}

interface NotesPayload {
  data: NoteRow[];
  meta: { canWrite: boolean; currentUserId: number | null };
}

export function Notes({
  entityType,
  entityId,
}: {
  entityType: 'order' | 'customer';
  entityId: number;
}) {
  const path = `/admin/notes?entityType=${entityType}&entityId=${entityId}`;
  const { state, reload } = useResource<NotesPayload>(path);
  const [draft, setDraft] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [editBody, setEditBody] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /*
   * دخول التحرير وخروجه لا يُسقطان التركيز.
   *
   * زرّ «تعديل» **يُستبدل** بالنموذج (شرطه `editing !== note.id`)، فالضغط عليه
   * بلوحة المفاتيح يفكّ العنصر المركَّز ويصير `document.activeElement` هو
   * `<body>`: التبويب التالي يبدأ من رأس المستند، ومنطقة النصّ التي فُتحت
   * لتوّها لا يصلها المستعمل إلّا بعبور الصفحة كلّها.
   *
   * والعودة بالمعرّف لا بمرجعٍ محفوظ: الزرّ يُفكّ عند التحرير ويُركَّب من
   * جديد عند الإلغاء — عقدةٌ أخرى، ومرجعٌ إلى المفكوكة `focus()` عليه لا يفعل
   * شيئًا. أمّا المعرّف فيصف الزرّ الحيّ أيًّا كانت عقدته.
   */
  const editRef = useRef<HTMLTextAreaElement>(null);
  const lastEditing = useRef<number | null>(null);
  const editButtonId = (noteId: number) => `note-edit-${entityType}-${entityId}-${noteId}`;
  useEffect(() => {
    if (editing !== null) editRef.current?.focus();
    else if (lastEditing.current !== null) {
      // الملاحظة قد تكون حُذفت أو أُعيد جلبها — والزرّ الغائب لا يُركَّز عليه.
      document.getElementById(editButtonId(lastEditing.current))?.focus();
    }
    lastEditing.current = editing;
    // `editButtonId` دالّة نقيّة على قيمٍ ثابتة في عمر المكوّن.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- editButtonId دالّة نقيّة على قيمٍ ثابتة في عمر المكوّن
  }, [editing]);

  const send = useCallback(
    async (task: () => Promise<{ ok: boolean } & Record<string, unknown>>) => {
      setBusy(true);
      setError(null);
      const result = await task();
      setBusy(false);
      if (!result.ok) {
        setError(firstError(result as Parameters<typeof firstError>[0]));
        return false;
      }
      reload();
      return true;
    },
    [reload],
  );

  const add = async (event: FormEvent) => {
    event.preventDefault();
    const body = draft.trim();
    if (body === '') return;
    const ok = await send(() => api.post('/admin/notes', { entityType, entityId, body }));
    if (ok) setDraft('');
  };

  return (
    <section className="panel">
      <h2>ملاحظات المشغّلين</h2>

      {error !== null ? (
        <p className="state-line" role="alert">
          {error}
        </p>
      ) : null}

      <ResourceView state={state} reload={reload}>
        {(payload) => (
          <>
            {payload.meta.canWrite ? (
              <form className="filters" onSubmit={(event) => void add(event)}>
                <label className="field field-wide">
                  <span>ملاحظة جديدة</span>
                  <textarea
                    value={draft}
                    rows={2}
                    maxLength={4000}
                    placeholder="ما جرى، ومن قرّر، ولماذا."
                    onChange={(event) => setDraft(event.target.value)}
                  />
                </label>
                <button type="submit" className="btn btn-primary" disabled={busy || draft.trim() === ''}>
                  إضافة
                </button>
              </form>
            ) : (
              /* لا حقلَ معطّلًا بلا سبب: الدور يُقال باسمه. */
              <p className="page-note">
                دورك يقرأ الملاحظات ولا يكتبها — الكتابة تحتاج صلاحية «كتابة ملاحظات».
              </p>
            )}

            {payload.data.length === 0 ? (
              <p className="page-note">لا ملاحظات على هذا السجلّ بعد.</p>
            ) : (
              <ul className="notes-list">
                {payload.data.map((note) => (
                  <li key={note.id} className="note">
                    {editing === note.id ? (
                      <form
                        className="filters"
                        onSubmit={(event) => {
                          event.preventDefault();
                          void send(() =>
                            api.patch(`/admin/notes/${note.id}`, { body: editBody.trim() }),
                          ).then((ok) => {
                            if (ok) setEditing(null);
                          });
                        }}
                      >
                        <label className="field field-wide">
                          <span className="sr-only">تعديل الملاحظة</span>
                          <textarea
                            ref={editRef}
                            value={editBody}
                            rows={2}
                            maxLength={4000}
                            onChange={(event) => setEditBody(event.target.value)}
                          />
                        </label>
                        <button type="submit" className="btn btn-primary" disabled={busy}>
                          حفظ
                        </button>
                        <button type="button" className="btn btn-ghost" onClick={() => setEditing(null)}>
                          إلغاء
                        </button>
                      </form>
                    ) : (
                      <p className="note-body">{note.body}</p>
                    )}

                    <p className="note-meta">
                      <bdi dir="ltr">{note.authorEmail}</bdi> · {dateTime(note.createdAt)}
                      {note.edited ? <> · عُدّلت {dateTime(note.editedAt)}</> : null}
                      {note.canEdit && editing !== note.id ? (
                        <>
                          {' · '}
                          <button
                            id={editButtonId(note.id)}
                            type="button"
                            className="link-button"
                            onClick={() => {
                              setEditing(note.id);
                              setEditBody(note.body);
                            }}
                          >
                            تعديل
                          </button>
                        </>
                      ) : null}
                      {note.canDelete ? (
                        <>
                          {' · '}
                          <button
                            type="button"
                            className="link-button"
                            disabled={busy}
                            onClick={() => void send(() => api.delete(`/admin/notes/${note.id}`))}
                          >
                            حذف
                          </button>
                        </>
                      ) : null}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </ResourceView>
    </section>
  );
}
