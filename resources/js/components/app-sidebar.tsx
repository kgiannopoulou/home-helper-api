import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bot,
    Brain,
    CalendarCheck,
    FolderGit2,
    HeartPulse,
    House,
    LayoutGrid,
    Lightbulb,
    Monitor,
    Sparkles,
    Sun,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    assistant,
    briefing,
    chores,
    computers,
    dashboard,
    health,
    memory,
    planner,
    smartHome,
    spending,
} from '@/routes';
import { show as household } from '@/routes/household';
import { health as systemHealth } from '@/routes/system';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Today', href: briefing(), icon: Sun },
    { title: 'Assistant', href: assistant(), icon: Bot },
    { title: 'Memory', href: memory(), icon: Brain },
    { title: 'Spending', href: spending(), icon: Wallet },
    { title: 'Health', href: health(), icon: HeartPulse },
    { title: 'Chores', href: chores(), icon: Sparkles },
    { title: 'Week planner', href: planner(), icon: CalendarCheck },
    { title: 'Smart home', href: smartHome(), icon: Lightbulb },
    { title: 'Computers', href: computers(), icon: Monitor },
    { title: 'Household', href: household(), icon: House },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/kgiannopoulou/home-helper-api',
        icon: FolderGit2,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const items: NavItem[] = auth.can?.viewSystemHealth
        ? [
              ...mainNavItems,
              { title: 'System health', href: systemHealth(), icon: Activity },
          ]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
