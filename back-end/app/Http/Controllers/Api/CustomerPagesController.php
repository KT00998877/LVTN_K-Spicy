<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Services\BlogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerPagesController extends Controller
{
    public function __construct(private readonly BlogService $blogs) {}

    private function published() { return Blog::query()->where('status','PUBLISHED')->whereNotNull('published_at')->where('published_at','<=',now()); }

    public function news(Request $request)
    {
        $d=$request->validate(['section'=>['required','in:NEWS,OPENING,PROMOTION']]);
        return response()->json($this->published()->where('news_section',$d['section'])->orderByDesc('published_at')->orderByDesc('id')->paginate(9,['id','title','slug','excerpt','cover_image','published_at','news_section','category','reading_time']));
    }

    public function article(string $slug) {
        $post = $this->published()->where('slug',$slug)->first();
        abort_unless($post, 404);

        // Tính related posts.
        $related = $this->blogs->relatedPosts($post, 3)->map(fn (Blog $b) => [
            'id'           => (int) $b->id,
            'title'        => $b->title,
            'slug'         => $b->slug,
            'excerpt'      => $b->excerpt,
            'cover_image'  => $b->cover_image,
            'published_at' => $b->published_at?->toIso8601String(),
            'reading_time' => (int) ($b->reading_time ?? 0),
        ])->values();

        $post->loadMissing('author:id,name');
        $payload = $post->toArray();
        $payload['author_name'] = $post->author?->name;
        $payload['related'] = $related;

        return response()->json(['data' => $payload]);
    }

    public function contact(Request $request) {
        $d=$request->validate(['name'=>['required','string','max:100'],'email'=>['required','email','max:150'],'phone'=>['nullable','string','max:20'],'address'=>['nullable','string','max:255'],'topic'=>['required','in:FEEDBACK,FRANCHISE,OTHER'],'message'=>['required','string','max:5000']]);
        DB::table('contact_messages')->insert($d+['created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Đã tiếp nhận lời nhắn của bạn.'],201);
    }
}
