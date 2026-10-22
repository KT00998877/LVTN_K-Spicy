export interface ReservationOptions {
    timezone: string
    opens_at: string
    closes_at: string
    min_advance_minutes: number
    max_guests: number
    max_note_length: number
    server_now: string
}

export interface ReservationForm {
    customer_name: string
    customer_phone: string
    reserved_date: string
    reserved_time: string
    guest_count: number
    location: string
    note: string
    table_id?: number | null
}

export function restaurantNow(timezone: string, now = Date.now()) {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(now)
    const get = (name: string) => parts.find(part => part.type === name)!.value
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}:${get('second')}`
}

export function normalizeReservation(form: ReservationForm): ReservationForm {
    return { ...form, customer_name: form.customer_name.trim(),
        customer_phone: form.customer_phone.replace(/[\s.\-]/g, '').replace(/^\+84/, '0'),
        location: form.location.trim(), note: form.note.trim() }
}

export function validateReservation(input: ReservationForm, options: ReservationOptions, now = Date.now()) {
    const form = normalizeReservation(input)
    const errors: Record<string, string> = {}
    const nameLength = [...form.customer_name].length
    if (!form.customer_name) errors.customer_name = 'Vui lòng nhập họ và tên.'
    else if (nameLength < 2) errors.customer_name = 'Họ tên phải có ít nhất 2 ký tự.'
    else if (nameLength > 100) errors.customer_name = 'Họ tên không được vượt quá 100 ký tự.'
    else if (!/^\p{L}[\p{L}\p{M} '’\-]*$/u.test(form.customer_name)) errors.customer_name = 'Họ tên chỉ được chứa chữ, khoảng trắng, dấu gạch nối hoặc dấu nháy; không được chỉ chứa số.'
    if (!form.customer_phone) errors.customer_phone = 'Vui lòng nhập số điện thoại.'
    else if (!/^0[35789][0-9]{8}$/.test(form.customer_phone)) errors.customer_phone = 'Số điện thoại không đúng định dạng di động Việt Nam.'
    if (!Number.isInteger(form.guest_count) || form.guest_count < 1) errors.guest_count = 'Số khách phải là số nguyên từ 1 người trở lên.'
    else if (form.guest_count > options.max_guests) errors.guest_count = `Nhà hàng chỉ hỗ trợ đặt tối đa ${options.max_guests} khách mỗi lượt. Vui lòng liên hệ trực tiếp nếu cần đặt nhóm lớn.`
    const parsedDate = new Date(`${form.reserved_date}T00:00:00Z`)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.reserved_date) || !Number.isFinite(parsedDate.getTime()) || parsedDate.toISOString().slice(0, 10) !== form.reserved_date) errors.reserved_date = 'Vui lòng chọn ngày đặt bàn hợp lệ.'
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(form.reserved_time)) errors.reserved_time = 'Vui lòng chọn giờ hợp lệ theo định dạng HH:mm.'
    const localNow = restaurantNow(options.timezone, now)
    if (!errors.reserved_date && form.reserved_date < localNow.slice(0, 10)) errors.reserved_date = 'Không thể đặt bàn cho ngày đã qua.'
    if (!errors.reserved_date && !errors.reserved_time) {
        const at = `${form.reserved_date}T${form.reserved_time}:00`
        if (at <= localNow) errors.reserved_time = 'Thời gian đặt bàn phải lớn hơn thời gian hiện tại.'
        else if (at < restaurantNow(options.timezone, now + options.min_advance_minutes * 60000)) errors.reserved_time = `Vui lòng đặt bàn trước ít nhất ${options.min_advance_minutes} phút.`
        else if (form.reserved_time < options.opens_at || form.reserved_time > options.closes_at) errors.reserved_time = `Nhà hàng nhận đặt bàn từ ${options.opens_at} đến ${options.closes_at}.`
    }
    if (!['', 'Tầng 1', 'Tầng 2'].includes(form.location)) errors.location = 'Khu vực đặt bàn không hợp lệ.'
    if ([...form.note].length > options.max_note_length) errors.note = `Ghi chú không được vượt quá ${options.max_note_length} ký tự.`
    else if (/[<>]/.test(form.note)) errors.note = 'Ghi chú không được chứa thẻ HTML hoặc script.'
    return errors
}
