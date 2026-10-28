<?php

use App\Support\LegacyImageUrl;

it('builds legacy mode 4 and mode 2 URLs from relative or root-relative paths', function () {
    expect(LegacyImageUrl::fit('images/cars/3/profile.jpg?123', 500, 500))->toBe('/images/4/500/500/images/cars/3/profile.jpg?123')
        ->and(LegacyImageUrl::fit('/images/judging/7/3/a.jpg', 200, 150))->toBe('/images/4/200/150/images/judging/7/3/a.jpg')
        ->and(LegacyImageUrl::crop('images/gallery/1/2/12.jpg', 320, 230))->toBe('/images/2/320/230/5/images/gallery/1/2/12.jpg');
});
