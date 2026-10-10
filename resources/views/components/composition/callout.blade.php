<section class="cms-container cms-section cms-card cms-stack">
    @if(filled($params['heading'] ?? null))<h2 class="cms-title">{{ $params['heading'] }}</h2>@endif
    @if(filled($params['body'] ?? null))<p class="cms-lead">{{ $params['body'] }}</p>@endif
    @if(!empty($params['image_id']))
        <x-responsive-img :media="\App\Models\Media::find((int) $params['image_id'])" class="cms-media" />
    @endif
</section>
