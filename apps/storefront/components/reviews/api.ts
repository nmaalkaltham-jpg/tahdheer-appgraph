/**
 * Reviews API fetchers.
 *
 * INTERIM AUTHENTICATION: writes carry the `X-Order-Token` header — the guest
 * order token issued once at checkout. It is passed in from the caller (the
 * order page owns it); this module never reads or stores it anywhere, so it
 * cannot leak into localStorage or a URL.
 *
 * Never throws — an unreachable or unconfigured API surfaces as status 0 so
 * callers render the honest state instead of fabricated content.
 */
import { apiBase } from '../../lib/api';
import { budget } from '../../lib/fetch-budget';
import type {
  ReviewsPayload,
  ReviewsQuery,
  ReviewsResult,
  SubmitReviewInput,
  SubmittedReview,
  VoteResult,
} from './types';

const ORDER_TOKEN_HEADER = 'X-Order-Token';

/** Parses the body for the server's own Arabic message — we never write one. */
async function toResult<T>(res: Response): Promise<ReviewsResult<T>> {
  let payload: unknown = null;
  try {
    payload = await res.json();
  } catch {
    payload = null;
  }

  if (res.ok) return { data: payload as T, status: res.status };

  const body = (payload ?? {}) as { message?: string; reason?: string };
  return { data: null, status: res.status, message: body.message, reason: body.reason };
}

export function reviewsPath(productId: number | string, query: ReviewsQuery = {}): string {
  const params = new URLSearchParams();
  if (query.sort) params.set('sort', query.sort);
  if (query.stars) params.set('stars', String(query.stars));
  if (query.q) params.set('q', query.q);
  if (query.cursor) params.set('cursor', query.cursor);
  const qs = params.toString();
  return `/api/v1/products/${productId}/reviews${qs ? `?${qs}` : ''}`;
}

export async function getReviews(
  productId: number | string,
  query: ReviewsQuery = {},
  signal?: AbortSignal,
): Promise<ReviewsResult<ReviewsPayload>> {
  const base = apiBase();
  if (!base) return { data: null, status: 0 };

  try {
    const res = await fetch(`${base}${reviewsPath(productId, query)}`, {
      headers: { Accept: 'application/json' },
      signal,
    });
    return await toResult<ReviewsPayload>(res);
  } catch {
    return { data: null, status: 0 };
  }
}

export async function submitReview(
  productId: number | string,
  input: SubmitReviewInput,
  orderToken: string,
): Promise<ReviewsResult<SubmittedReview>> {
  const base = apiBase();
  if (!base) return { data: null, status: 0 };

  try {
    const res = await fetch(`${base}/api/v1/products/${productId}/reviews`, {
      signal: budget('action'),
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        [ORDER_TOKEN_HEADER]: orderToken,
      },
      body: JSON.stringify(input),
    });
    return await toResult<SubmittedReview>(res);
  } catch {
    return { data: null, status: 0 };
  }
}

export async function voteHelpful(
  reviewId: number | string,
  orderToken: string,
): Promise<ReviewsResult<VoteResult>> {
  const base = apiBase();
  if (!base) return { data: null, status: 0 };

  try {
    const res = await fetch(`${base}/api/v1/reviews/${reviewId}/votes`, {
      signal: budget('action'),
      method: 'POST',
      headers: { Accept: 'application/json', [ORDER_TOKEN_HEADER]: orderToken },
    });
    return await toResult<VoteResult>(res);
  } catch {
    return { data: null, status: 0 };
  }
}
