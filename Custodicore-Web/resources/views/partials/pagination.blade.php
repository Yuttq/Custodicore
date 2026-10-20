{{--
  Page links for layouts/app pages (plain CSS, no Tailwind). Laravel's default
  pagination view expects Tailwind, so its arrow icons render huge here.
  Use: {{ $items->links('partials.pagination') }}
  The "Showing x–y of z" text stays in each page's own footer.
--}}
@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Pages">
    @if ($paginator->onFirstPage())
      <span class="pager-btn is-disabled" aria-disabled="true">‹ Prev</span>
    @else
      <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
    @endif

    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="pager-gap">{{ $element }}</span>
      @endif
      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="pager-btn is-current" aria-current="page">{{ $page }}</span>
          @else
            <a class="pager-btn" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach

    @if ($paginator->hasMorePages())
      <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
    @else
      <span class="pager-btn is-disabled" aria-disabled="true">Next ›</span>
    @endif
  </nav>
@endif
