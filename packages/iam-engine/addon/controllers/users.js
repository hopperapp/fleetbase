import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';

export default class UsersController extends Controller {
    @service hostRouter;

    get tabs() {
        return [
            {
                route: 'users.index',
                label: 'Users',
            },
            {
                route: 'users.drivers',
                label: 'Drivers',
            },
            {
                route: 'users.customers',
                label: 'Customers',
            },
        ];
    }

    get childController() {
        const owner = getOwner(this);
        const fullRouteName = this.hostRouter.currentRouteName;

        // strip engine mount prefix once
        const mount = owner.mountPoint;
        let local = fullRouteName;
        if (mount && local.startsWith(mount + '.')) {
            local = local.slice(mount.length + 1);
        }

        const childController = owner.lookup(`controller:${local}`);
        return childController;
    }
}
