<section class="cms-container cms-section cms-stack" data-cms-accordion>
    @if(filled($params['heading'] ?? null))<h2 class="cms-title">{{ $params['heading'] }}</h2>@endif
    @foreach(($params['items'] ?? []) as $index => $item)
        <details class="cms-card" @if(isset($params['initially_open']) && (int) $params['initially_open'] === $index) open @endif>
            <summary>{{ $item['question'] ?? '' }}</summary>
            <p class="cms-accordion-answer">{{ $item['answer'] ?? '' }}</p>
        </details>
    @endforeach
</section>
