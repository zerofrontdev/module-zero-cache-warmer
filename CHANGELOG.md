# Changelog: zerofront/module-zero-cache-warmer

## 1.3.6

- A run warms by priority across all store views: every home page first, then top-level categories, deeper categories, CMS pages, extra paths and products, one page of each store view in turn.
- Each URL is asked once per run, also when store views share an address.
- Addresses that can only answer 404 are left out: categories outside a store view's root category and empty URL keys.
- `zero:cache:warm --later` honours `--store`.
- A storefront that stops answering is skipped instead of waited for page by page; a run that hits the load limit for too long stops and puts what it took back in the queue.

Earlier releases (1.3.5 and before): see the Git tags.
