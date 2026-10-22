<script setup lang="ts">
import NavbarHome from '@/components/home/NavbarHome.vue'
import FooterHome from '@/components/home/FooterHome.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { imageUrl } from '@/utils/image'
</script>

<template>
    <div class="about-page">
        <NavbarHome />
        
        <main>
            <section class="about-hero" aria-labelledby="about-title">
                <div class="hero-bg">
                    <img :src="imageUrl('/storage/brand/hero-bg.jpg')" alt="" aria-hidden="true" />
                    <div class="overlay"></div>
                </div>
                <div class="container hero-content">
                    <span class="hero-kicker">Về K-SPICY</span>
                    <h1 id="about-title">Câu Chuyện Của K-SPICY</h1>
                    <p>Hành trình mang hương vị Hàn Quốc đích thực đến Việt Nam.</p>
                    <div class="hero-actions">
                        <UiButton as="router-link" to="/thuc-don" variant="primary" size="lg">Khám phá thực đơn</UiButton>
                        <UiButton as="router-link" to="/dat-ban" variant="outline-white" size="lg">Đặt bàn</UiButton>
                    </div>
                </div>
            </section>
            
            <section class="about-story section-padding">
                <div class="container">
                    <div class="story-grid">
                        <div class="story-text">
                            <span class="kicker">Khởi nguồn</span>
                            <h2>Từ Đam Mê Đến Trải Nghiệm Hoàn Hảo</h2>
                            <p>Được thành lập vào năm 2020, K-SPICY bắt nguồn từ niềm đam mê sâu sắc với ẩm thực đường phố Hàn Quốc. Chúng tôi mong muốn tạo ra một không gian nơi thực khách không chỉ thưởng thức những món ăn ngon mà còn cảm nhận được văn hóa năng động của xứ sở Kim Chi.</p>
                            <p>Mỗi bát mì cay, mỗi cuộn kimbap hay từng miếng gà rán đều được chúng tôi chăm chút tỉ mỉ từ khâu chọn nguyên liệu đến cách chế biến, đảm bảo mang đến hương vị chuẩn mực và trải nghiệm bùng nổ cho vị giác của bạn.</p>
                        </div>
                        <div class="story-image">
                            <img :src="imageUrl('/storage/brand/hero.jpg')" alt="Món ăn đặc trưng của K-SPICY" loading="lazy" decoding="async" />
                        </div>
                    </div>
                </div>
            </section>
            
            <section class="about-values bg-cream section-padding">
                <div class="container">
                    <div class="values-header text-center">
                        <h2>Giá Trị Cốt Lõi</h2>
                        <p>Những nguyên tắc định hình nên mọi hoạt động của K-SPICY.</p>
                    </div>
                    
                    <div class="values-grid">
                        <div class="value-card">
                            <div class="value-number">01</div>
                            <h3>Chất lượng hàng đầu</h3>
                            <p>Chúng tôi chỉ sử dụng những nguyên liệu tươi ngon nhất, nhập khẩu trực tiếp từ các nhà cung cấp uy tín để đảm bảo hương vị và độ an toàn.</p>
                        </div>
                        <div class="value-card">
                            <div class="value-number">02</div>
                            <h3>Công thức chuẩn vị</h3>
                            <p>Sự kết hợp hoàn hảo giữa các loại gia vị đặc trưng mang đến nước dùng đậm đà, chuẩn phong vị Hàn Quốc mà vẫn phù hợp với khẩu vị người Việt.</p>
                        </div>
                        <div class="value-card">
                            <div class="value-number">03</div>
                            <h3>Phục vụ tận tâm</h3>
                            <p>Không gian hiện đại, sạch sẽ cùng đội ngũ nhân viên nhiệt tình, chuyên nghiệp luôn sẵn sàng mang đến cho bạn trải nghiệm dùng bữa trọn vẹn nhất.</p>
                        </div>
                    </div>
                </div>
            </section>
            
            <section class="about-space section-padding">
                <div class="container">
                    <div class="story-grid reverse">
                        <div class="story-text">
                            <span class="kicker">Không gian</span>
                            <h2>Thiết Kế Hiện Đại & Năng Động</h2>
                            <p>Lấy cảm hứng từ những nhà hàng phong cách Seoul, không gian của K-SPICY được thiết kế với tông màu đỏ - đen làm chủ đạo, kết hợp cùng ánh sáng ấm áp tạo nên bầu không khí trẻ trung nhưng không kém phần sang trọng.</p>
                            <p>Dù bạn đi một mình, hẹn hò hay tụ họp cùng gia đình và bạn bè, chúng tôi luôn có những góc ngồi lý tưởng để bạn thoải mái tận hưởng bữa ăn.</p>
                        </div>
                        <div class="story-image">
                            <img :src="imageUrl('/storage/brand/restaurant.jpg')" alt="Không gian nhà hàng K-SPICY" loading="lazy" decoding="async" />
                        </div>
                    </div>
                </div>
            </section>
        </main>
        
        <FooterHome />
    </div>
</template>

<style scoped>
.about-page { background: var(--color-background); }
.about-page .container {
    width: min(100%, var(--container-wide, 1280px));
    margin-inline: auto;
    padding-inline: clamp(20px, 4vw, 48px);
}
.about-hero {
    position: relative;
    isolation: isolate;
    background: var(--color-brand-dark);
    margin-top: var(--navbar-height);
    padding-block: clamp(64px, 9vw, 112px);
    text-align: center;
}
.hero-bg { position: absolute; inset: 0; z-index: 0; overflow: hidden; }
.hero-bg img { width: 100%; height: 100%; object-fit: cover; }
.overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(110deg,
        color-mix(in srgb, var(--color-brand-dark) 94%, transparent),
        color-mix(in srgb, var(--color-brand-primary) 82%, transparent));
}
.hero-content { position: relative; z-index: 1; color: var(--color-text-inverse); }
.hero-kicker {
    display: inline-block;
    color: var(--color-brand-muted-inverse);
    font-size: 14px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 20px;
}
.hero-content h1 {
    max-width: 18ch;
    margin: 0 auto 24px;
    font-size: clamp(36px, 5.5vw, 68px);
    font-weight: 900;
    line-height: 1.2;
    letter-spacing: -.035em;
    text-wrap: balance;
}
.hero-content p {
    max-width: 52ch;
    margin: 0 auto;
    font-size: clamp(16px, 2vw, 20px);
    line-height: 1.7;
    color: var(--color-brand-muted-inverse);
}
.hero-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; margin-top: 32px; }
.section-padding { padding-block: clamp(48px, 7vw, 88px); }
.story-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 32px; align-items: center; }
.story-text, .story-image { min-width: 0; }
.kicker {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--color-brand-action);
    margin-bottom: 16px;
}
.kicker::before { content: ''; width: 28px; height: 3px; background: currentColor; }
.story-text h2, .values-header h2 {
    font-size: clamp(28px, 3.3vw, 42px);
    font-weight: 800;
    color: var(--color-brand-ink);
    margin: 0 0 24px;
    line-height: 1.3;
    letter-spacing: -.025em;
    text-wrap: balance;
}
.story-text p, .value-card p {
    font-size: 16px;
    line-height: 1.8;
    color: var(--color-text-muted);
    margin: 0 0 20px;
}
.story-text p:last-child, .value-card p:last-child { margin-bottom: 0; }
.story-image {
    padding: 12px;
    background: var(--color-brand-soft);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
}
.story-image img {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    border-radius: var(--radius-lg);
}
.about-values { background: var(--color-brand-soft); border-block: 1px solid var(--color-border); }
.values-header { max-width: 64ch; margin: 0 auto 36px; text-align: center; }
.values-header h2 { margin-bottom: 16px; }
.values-header p { font-size: 16px; line-height: 1.7; color: var(--color-text-muted); margin: 0; }
.values-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; }
.value-card {
    background: var(--color-surface);
    padding: clamp(24px, 3vw, 32px);
    border: 1px solid var(--color-border);
    border-top: 4px solid var(--color-brand-action);
    border-radius: var(--radius-lg);
}
.value-number {
    font-size: 36px;
    font-weight: 900;
    color: var(--color-brand-action);
    margin-bottom: 24px;
    line-height: 1;
    font-family: var(--font-display);
}
.value-card h3 { font-size: 20px; font-weight: 800; color: var(--color-brand-ink); line-height: 1.4; margin: 0 0 16px; }
@media (min-width: 1024px) {
    .story-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 56px; }
    .story-grid.reverse .story-text { order: 2; }
    .story-grid.reverse .story-image { order: 1; }
    .values-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 768px) {
    .about-hero { margin-top: var(--navbar-height-mobile); }
}
@media (max-width: 480px) {
    .hero-actions { flex-direction: column; align-items: stretch; }
}
</style>
