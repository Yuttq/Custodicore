<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['title'] }} · CustodiCore</title>
    {{-- Public page, readable before registering: no Vite / no login needed. --}}
    <style>
        :root { --navy: #0F3D7A; --teal: #0DA58A; --amber: #F59E0B; --bg: #F8FAFC; --card: #FFFFFF; --border: #E5E7EB; --text: #111827; --muted: #6B7280; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; }
        header { background: var(--navy); color: #fff; padding: 16px 24px; font-weight: 700; }
        main { max-width: 760px; margin: 32px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px; }
        h1 { margin: 0 0 4px; font-size: 26px; color: var(--navy); }
        .meta { margin: 0 0 24px; font-size: 13px; color: var(--muted); }
        h2 { margin: 28px 0 8px; font-size: 17px; }
        p { margin: 0 0 12px; }
        .draft { margin: 0 0 24px; padding: 12px 16px; border: 1px solid var(--amber); border-left-width: 4px; border-radius: 12px; background: #FFFBEB; font-size: 13px; }
        nav { margin-top: 32px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 14px; }
        a { color: #2563EB; font-weight: 600; }
    </style>
</head>
<body>
    <header>CustodiCore</header>
    <main>
        <div class="card">
            <h1>{{ $doc['title'] }}</h1>
            <p class="meta">Version {{ $version }} · Effective {{ $effective }}</p>

            @if ($draft)
                <div class="draft">
                    <strong>Draft — pending legal review.</strong>
                    This text has not yet been approved by the facility's legal counsel or Data Protection Officer.
                </div>
            @endif

            <p>{{ $doc['intro'] }}</p>

            @foreach ($doc['sections'] as $section)
                <h2>{{ $section['heading'] }}</h2>
                @foreach ($section['body'] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            @endforeach

            <nav>
                Also read: <a href="{{ route($otherRoute) }}">{{ $other['title'] }}</a>
            </nav>
        </div>
    </main>
</body>
</html>
