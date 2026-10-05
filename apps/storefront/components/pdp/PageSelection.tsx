'use client';
import { createContext, useContext, useState, type ReactNode } from 'react';

/**
 * The gallery's selected page, shared by the stage, its thumbnail rail and the
 * preview cards in «المعاينة» — Storefront Product.dc.html drives all three
 * from one `page` index, so picking a card in the tab changes the stage.
 */
interface PageSelection {
  page: number;
  pick: (index: number) => void;
}

const Context = createContext<PageSelection>({ page: 0, pick: () => {} });

export function PageSelectionProvider({ children }: { children: ReactNode }) {
  const [page, setPage] = useState(0);
  return <Context.Provider value={{ page, pick: setPage }}>{children}</Context.Provider>;
}

export function usePageSelection(): PageSelection {
  return useContext(Context);
}
