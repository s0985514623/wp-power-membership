(function ($) {
  const url = new URL(window.location.href);
  const postId = url.searchParams.get("post");
  const default_member_lv_id = window?.member_lv_data?.default_member_lv_id;
	// console.log("🚀 ~ default_member_lv_id:", default_member_lv_id)
  // console.log("⭐  !==:", default_member_lv_id !== postId);

  if (default_member_lv_id !== postId) return;
  const names = ["power_membership_threshold", "menu_order","power_membership_validity_period"];

  names.forEach((name) => {
    $(`input[name="${name}"]`).attr("disabled", true).css({
      cursor: "not-allowed",
    });
  });
})(jQuery);

